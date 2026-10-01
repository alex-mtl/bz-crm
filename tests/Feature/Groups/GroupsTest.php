<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Exceptions\GroupRuleViolation;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupInvitation;
use App\Domain\Groups\Models\GroupJoinRequest;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Groups\Notifications\GroupNotice;
use App\Domain\Messaging\Discussions;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OrgFixture;

/*
 * ФО §6.5 — groups: open / closed / secret, roles inside the group, requests, invitations (personal, by link,
 * in bulk), binding to a unit or a territory, the chat of the group.
 */

beforeEach(function () {
    $this->org = OrgFixture::build();
    $this->groups = app(ManageGroups::class);
    $this->access = app(GroupAccess::class);
});

it('creates a group with its owner and its chat', function () {
    $o = $this->org;

    $group = $this->groups->create($o->a1, ['name' => ' Voluntari Centru ', 'description' => 'Echipa sectorului']);
    $chat = app(Discussions::class)->findFor($group);

    expect($group)->name->toBe('Voluntari Centru')->type->toBe(Group::OPEN)
        ->and($this->access->roleOf($group, $o->a1->person_id))->toBe(GroupMember::OWNER)
        ->and($chat)->not->toBeNull()
        ->and(DB::table('chat_members')->where('chat_id', $chat->id)->pluck('person_id')->all())->toBe([$o->a1->person_id])
        ->and(journalCount('groups.group.created'))->toBe(1)
        ->and(fn () => $this->groups->create($o->a1, ['name' => '  ']))->toThrow(GroupRuleViolation::class)
        ->and(fn () => $this->groups->create($o->a1, ['name' => 'X', 'type' => 'hidden']))->toThrow(GroupRuleViolation::class);
});

it('admits to an open group at once and keeps the chat in step', function () {
    $o = $this->org;
    $group = $this->groups->create($o->a1, ['name' => 'Deschis']);

    $member = $this->groups->join($o->b1, $group);
    $chat = app(Discussions::class)->findFor($group);

    expect($member)->toBeInstanceOf(GroupMember::class)->role->toBe(GroupMember::MEMBER)
        ->and(DB::table('chat_members')->where('chat_id', $chat->id)->count())->toBe(2)
        ->and(fn () => $this->groups->join($o->b1, $group))->toThrow(GroupRuleViolation::class);

    $this->groups->leave($o->b1, $group);

    expect($this->access->isMember($group, $o->b1->person_id))->toBeFalse()
        ->and(DB::table('chat_members')->where('chat_id', $chat->id)->count())->toBe(1)
        ->and(fn () => $this->groups->leave($o->a1, $group))->toThrow(GroupRuleViolation::class)
        ->and(journalCount('groups.member.left'))->toBe(1);
});

it('takes a request to a closed group and lets only its managers decide', function () {
    Notification::fake();
    $o = $this->org;
    $group = $this->groups->create($o->a1, ['name' => 'Închis', 'type' => Group::CLOSED]);

    $request = $this->groups->join($o->b1, $group, 'Vreau să ajut');
    $second = $this->groups->join($o->balti1, $group);

    expect($request)->toBeInstanceOf(GroupJoinRequest::class)->status->toBe(GroupJoinRequest::PENDING)
        ->and($this->access->isMember($group, $o->b1->person_id))->toBeFalse()
        ->and(fn () => $this->groups->join($o->b1, $group))->toThrow(GroupRuleViolation::class)
        ->and(fn () => $this->groups->decideRequest($o->a2, $request, true))->toThrow(AuthorizationException::class)
        // A unit head is not a manager of somebody's group.
        ->and(fn () => $this->groups->decideRequest($o->headA, $request, true))->toThrow(AuthorizationException::class);

    $this->groups->decideRequest($o->a1, $request, true);
    $this->groups->decideRequest($o->a1, $second, false);

    expect($this->access->isMember($group, $o->b1->person_id))->toBeTrue()
        ->and($this->access->isMember($group, $o->balti1->person_id))->toBeFalse()
        ->and($second->fresh()->status)->toBe(GroupJoinRequest::REJECTED)
        ->and(fn () => $this->groups->decideRequest($o->a1, $request->fresh(), false))->toThrow(GroupRuleViolation::class)
        ->and(journalCount('groups.request.rejected'))->toBe(1);
    Notification::assertSentTo($o->b1, GroupNotice::class, fn (GroupNotice $notice) => $notice->kind === GroupNotice::REQUEST_APPROVED);
    Notification::assertSentTo($o->balti1, GroupNotice::class, fn (GroupNotice $notice) => $notice->kind === GroupNotice::REQUEST_REJECTED);
});

it('keeps a secret group invisible to everyone but its members and the invited', function () {
    Notification::fake();
    $o = $this->org;
    $open = $this->groups->create($o->a1, ['name' => 'Deschis']);
    $secret = $this->groups->create($o->a1, ['name' => 'Secret', 'type' => Group::SECRET]);

    expect($this->access->visible($o->b1)->pluck('id')->all())->toBe([$open->id])
        ->and($this->access->visible($o->b1)->count())->toBe(1)
        ->and($this->access->visible($o->a1)->count())->toBe(2)
        ->and($this->access->canSee($o->b1, $secret))->toBeFalse()
        // Even the organization head does not see a secret group they are not in.
        ->and($this->access->canSee($o->orgHead, $secret))->toBeFalse()
        ->and(fn () => $this->groups->join($o->b1, $secret))->toThrow(AuthorizationException::class);

    $invitation = $this->groups->invite($o->a1, $secret, $o->b1->person);

    expect($this->groups->invitationsFor($o->b1)->pluck('group_id')->all())->toBe([$secret->id])
        ->and($this->access->canSee($o->b1, $secret))->toBeFalse()
        ->and(fn () => $this->groups->invite($o->a1, $secret, $o->b1->person))->toThrow(GroupRuleViolation::class)
        ->and(fn () => $this->groups->answerInvitation($o->a2, $invitation, true))->toThrow(GroupRuleViolation::class);
    Notification::assertSentTo($o->b1, GroupNotice::class, fn (GroupNotice $notice) => $notice->kind === GroupNotice::INVITED);

    $this->groups->answerInvitation($o->b1, $invitation, true);

    expect($this->access->canSee($o->b1, $secret))->toBeTrue()
        ->and($invitation->fresh()->status)->toBe(GroupInvitation::ACCEPTED)
        ->and($this->access->visible($o->b1)->count())->toBe(2);
});

it('declines and revokes invitations', function () {
    $o = $this->org;
    $group = $this->groups->create($o->a1, ['name' => 'Secret', 'type' => Group::SECRET]);
    $declined = $this->groups->invite($o->a1, $group, $o->b1->person);
    $revoked = $this->groups->invite($o->a1, $group, $o->a2->person);

    $this->groups->answerInvitation($o->b1, $declined, false);
    $this->groups->revokeInvitation($o->a1, $revoked);

    expect($declined->fresh()->status)->toBe(GroupInvitation::DECLINED)
        ->and($revoked->fresh()->status)->toBe(GroupInvitation::REVOKED)
        ->and($this->access->isMember($group, $o->b1->person_id))->toBeFalse()
        ->and(fn () => $this->groups->answerInvitation($o->a2, $revoked->fresh(), true))->toThrow(GroupRuleViolation::class)
        ->and(fn () => $this->groups->revokeInvitation($o->a1, $revoked->fresh()))->toThrow(GroupRuleViolation::class)
        ->and(journalCount('groups.invitation.declined'))->toBe(1)
        ->and(journalCount('groups.invitation.revoked'))->toBe(1);
});

it('admits by an invitation link until it runs out', function () {
    $o = $this->org;
    $group = $this->groups->create($o->a1, ['name' => 'Închis', 'type' => Group::CLOSED]);
    ['invitation' => $invitation, 'token' => $token] = $this->groups->inviteByLink($o->a1, $group, now()->addDay(), 1);

    expect($invitation->token_hash)->not->toBe($token)
        ->and(fn () => $this->groups->inviteByLink($o->a2, $group))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->groups->joinByLink($o->b1, 'wrong-token'))->toThrow(GroupRuleViolation::class);

    $this->groups->joinByLink($o->b1, $token);

    expect($this->access->isMember($group, $o->b1->person_id))->toBeTrue()
        // One use only.
        ->and(fn () => $this->groups->joinByLink($o->balti1, $token))->toThrow(GroupRuleViolation::class);

    ['token' => $expiring] = $this->groups->inviteByLink($o->a1, $group, now()->addHour());
    $this->travel(2)->hours();

    expect(fn () => $this->groups->joinByLink($o->balti1, $expiring))->toThrow(GroupRuleViolation::class);
});

it('invites in bulk only the people the bulk right reaches', function () {
    Notification::fake();
    $o = $this->org;
    $group = $this->groups->create($o->headA, ['name' => 'Filiala A', 'type' => Group::CLOSED]);
    $ofAnyone = $this->groups->create($o->a1, ['name' => 'Al lui a1']);

    $count = $this->groups->inviteBulk($o->headA, $group, Person::query());

    expect($count)->toBe(2)
        ->and(GroupInvitation::query()->where('group_id', $group->id)->pluck('person_id')->sort()->values()->all())
        ->toBe(collect([$o->a1->person_id, $o->a2->person_id])->sort()->values()->all())
        ->and($this->groups->inviteBulk($o->headA, $group, Person::query()))->toBe(0)
        ->and(journalCount('groups.invitation.bulk_sent'))->toBe(2)
        // An employee has no bulk right, even in their own group; a head — not in a group they do not manage.
        ->and(fn () => $this->groups->inviteBulk($o->a1, $ofAnyone, Person::query()))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->groups->inviteBulk($o->headA, $ofAnyone, Person::query()))->toThrow(AuthorizationException::class);
    Notification::assertSentTo($o->a2, GroupNotice::class);
    Notification::assertNotSentTo($o->b1, GroupNotice::class);
});

it('hands out roles inside the group by the rank of the one who assigns', function () {
    $o = $this->org;
    $group = $this->groups->create($o->a1, ['name' => 'Grup']);
    foreach ([$o->a2, $o->b1, $o->balti1] as $user) {
        $this->groups->join($user, $group);
    }

    $this->groups->setRole($o->a1, $group, $o->a2->person, GroupMember::ADMIN);
    $this->groups->setRole($o->a2, $group, $o->b1->person, GroupMember::MODERATOR);

    expect($this->access->canManage($o->a2, $group))->toBeTrue()
        ->and($this->access->canModerate($o->b1, $group))->toBeTrue()
        ->and($this->access->canManage($o->b1, $group))->toBeFalse()
        // An admin makes moderators, not admins; a moderator assigns nothing.
        ->and(fn () => $this->groups->setRole($o->a2, $group, $o->balti1->person, GroupMember::ADMIN))->toThrow(GroupRuleViolation::class)
        ->and(fn () => $this->groups->setRole($o->b1, $group, $o->balti1->person, GroupMember::MODERATOR))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->groups->setRole($o->a1, $group, $o->balti1->person, 'boss'))->toThrow(GroupRuleViolation::class)
        ->and(fn () => $this->groups->removeMember($o->a2, $group, $o->a1->person))->toThrow(GroupRuleViolation::class)
        ->and(journalCount('groups.member.role_changed'))->toBe(2);

    $this->groups->removeMember($o->a2, $group, $o->balti1->person);
    $this->groups->setRole($o->a1, $group, $o->a2->person, GroupMember::OWNER);

    expect($this->access->isMember($group, $o->balti1->person_id))->toBeFalse()
        ->and($this->access->roleOf($group, $o->a2->person_id))->toBe(GroupMember::OWNER)
        ->and($this->access->roleOf($group, $o->a1->person_id))->toBe(GroupMember::ADMIN);

    $this->groups->leave($o->a1, $group);

    expect($this->access->isMember($group, $o->a1->person_id))->toBeFalse();
});

it('binds a group to a unit or a territory only by a right of its own', function () {
    $o = $this->org;

    $bound = $this->groups->create($o->headA, ['name' => 'Filiala A', 'org_unit_id' => $o->branchA->id]);

    expect($bound->org_unit_id)->toBe($o->branchA->id)
        ->and(fn () => $this->groups->create($o->a1, ['name' => 'X', 'org_unit_id' => $o->branchA->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->groups->create($o->headA, ['name' => 'X', 'org_unit_id' => $o->branchB->id]))->toThrow(AuthorizationException::class)
        ->and($this->groups->create($o->orgHead, ['name' => 'Bălți', 'territory_id' => $o->baltiTerritory->id])->territory_id)->toBe($o->baltiTerritory->id);
});

it('lets the managers edit and archive the group, and the super admin step in', function () {
    $o = $this->org;
    $group = $this->groups->create($o->a1, ['name' => 'Grup']);
    $this->groups->join($o->a2, $group);
    app(AuthorizationService::class)->forget();

    $this->groups->update($o->a1, $group, ['name' => 'Grup nou', 'type' => Group::CLOSED, 'rules' => 'Fără spam']);

    expect($group->fresh())->name->toBe('Grup nou')->type->toBe(Group::CLOSED)
        ->and(journalCount('groups.group.updated'))->toBe(1)
        ->and(fn () => $this->groups->update($o->a2, $group, ['name' => 'Altul']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->groups->archive($o->a2, $group))->toThrow(AuthorizationException::class)
        ->and($this->access->canManage($o->admin, $group))->toBeTrue();

    $this->groups->archive($o->admin, $group->fresh());

    expect($group->fresh()->archived_at)->not->toBeNull()
        ->and($this->access->visible($o->b1)->count())->toBe(0)
        ->and($this->access->visible($o->a2)->count())->toBe(1)
        ->and(fn () => $this->groups->join($o->b1, $group->fresh()))->toThrow(AuthorizationException::class);
});
