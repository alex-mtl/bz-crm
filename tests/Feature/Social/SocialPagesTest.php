<?php

use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupJoinRequest;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\ModerationAction;
use App\Domain\Social\Models\ModerationReport;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Models\Reaction;
use App\Domain\Social\Moderation;
use App\Filament\Pages\GroupJoin;
use App\Filament\Pages\SocialFeed;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Pages\ViewGroup;
use App\Filament\Resources\Moderation\Pages\ListReports;
use App\Filament\Resources\Moderation\Pages\ListSanctions;
use App\Livewire\GroupDiscussion;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\SocialFixture;

/*
 * The screens and the API of phase 4 are a thin layer: they show what the domain lets the viewer see and
 * call the domain actions. These tests go through the pages the way a person does.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
});

it('shows in the feed only what the viewer may see — on the page, by a direct link and in the search', function () {
    $o = $this->org;
    $post = $this->social->centruPost('Întâlnire în sectorul Centru');
    $this->social->post($o->orgHead, 'Anunț pentru toți', ['visibility' => Post::PUBLIC]);

    $this->actingAs($o->a2)->get('/admin/feed')->assertOk()->assertSee('Întâlnire în sectorul Centru')->assertSee('Anunț pentru toți');
    $this->get('/admin/feed?post='.$post->id)->assertOk()->assertSee('Întâlnire în sectorul Centru');

    $this->flushSession();
    $this->actingAs($o->balti1)->get('/admin/feed')->assertOk()->assertDontSee('Întâlnire în sectorul Centru')->assertSee('Anunț pentru toți');
    $this->get('/admin/feed?post='.$post->id)->assertOk()->assertDontSee('Întâlnire în sectorul Centru')->assertSee(__('social.ui.empty'));
    $this->get('/admin/feed?search=Centru')->assertOk()->assertDontSee('Întâlnire în sectorul Centru');
});

it('publishes, edits, comments, reacts and reports from the feed page', function () {
    $o = $this->org;
    $this->actingAs($o->a1);

    Livewire::test(SocialFeed::class)
        ->callAction('compose', data: ['body' => 'Postare din pagină', 'visibility' => Post::REGIONAL, 'territory_ids' => [$o->centru->id], 'intent' => 'now'])
        ->assertHasNoActionErrors()
        ->assertSee('Postare din pagină');
    $post = Post::query()->sole();

    Livewire::test(SocialFeed::class)
        ->callAction('edit', data: ['body' => 'Postare modificată'], arguments: ['post' => $post->id])
        ->callAction('comment', data: ['body' => 'Primul comentariu'], arguments: ['post' => $post->id])
        ->call('react', 'post', $post->id, 'like')
        ->assertSee('Postare modificată')->assertSee('Primul comentariu')->assertSee(__('social.ui.edited'));

    // An audience out of the author's reach is refused by the domain, whatever the form sends.
    Livewire::test(SocialFeed::class)
        ->callAction('compose', data: ['body' => 'Pentru Botanica', 'visibility' => Post::REGIONAL, 'territory_ids' => [$o->botanica->id], 'intent' => 'now']);

    $this->flushSession();
    $this->actingAs($o->a2);
    Livewire::test(SocialFeed::class)
        ->callAction('report', data: ['reason' => 'spam'], arguments: ['type' => 'post', 'id' => $post->id])
        ->callAction('compose', data: ['body' => 'De citit', 'visibility' => Post::PRIVATE, 'intent' => 'now'], arguments: ['repost' => $post->id])
        ->call('toggleFollow', $o->a1->person_id)
        ->assertHasNoActionErrors();

    expect(Post::query()->count())->toBe(2)
        ->and($post->fresh()->body)->toBe('Postare modificată')
        ->and($post->revisions()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(1)
        ->and(Reaction::query()->count())->toBe(1)
        ->and(ModerationReport::query()->count())->toBe(1)
        ->and(Post::query()->where('repost_of_post_id', $post->id)->exists())->toBeTrue()
        ->and(app(ManageComments::class)->isFollowing($o->a2, $o->a1->person))->toBeTrue();
});

it('lets a head moderate and pin from the feed, and shows the author why the post was hidden', function () {
    $o = $this->org;
    $post = $this->social->centruPost('Informație neverificată');

    $this->actingAs($o->headA);
    Livewire::test(SocialFeed::class)
        ->callAction('pin', data: ['scope' => PostPin::TERRITORY, 'territory_id' => $o->centru->id], arguments: ['post' => $post->id])
        ->callAction('hide', data: ['reason' => 'Fără sursă'], arguments: ['type' => 'post', 'id' => $post->id])
        ->assertHasNoActionErrors();

    expect($post->fresh()->isHidden())->toBeTrue()->and(PostPin::query()->count())->toBe(1);

    $this->flushSession();
    $this->actingAs($o->a1)->get('/admin/feed?mode=mine')->assertOk()->assertSee('Fără sursă');
    $this->flushSession();
    $this->actingAs($o->a2)->get('/admin/feed')->assertOk()->assertDontSee('Informație neverificată');

    // Another branch: the buttons are absent, and the calls are refused.
    $this->flushSession();
    $this->actingAs($o->headB);
    Livewire::test(SocialFeed::class)->call('restore', 'post', $post->id);

    expect($post->fresh()->isHidden())->toBeTrue();
});

it('serves the feed over the API with the same visibility', function () {
    $o = $this->org;
    $post = $this->social->centruPost('Doar pentru Centru');
    app(ManageComments::class)->react($o->a2, $post, 'like');

    $this->getJson('/api/v1/feed')->assertUnauthorized();

    $this->actingAs($o->a2);
    $this->getJson('/api/v1/feed')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $post->id)->assertJsonPath('data.0.body', 'Doar pentru Centru')
        ->assertJsonPath('data.0.reactions.like', 1)->assertJsonPath('data.0.my_reaction', 'like')
        ->assertJsonPath('data.0.audience.0', $o->centru->name())->assertJsonPath('meta.has_more', false);
    $this->getJson('/api/v1/posts/'.$post->id)->assertOk()->assertJsonPath('data.visibility', Post::REGIONAL);
    $this->getJson('/api/v1/feed?mode=nonsense')->assertUnprocessable();

    $this->flushSession();
    $this->actingAs($o->balti1);
    $this->getJson('/api/v1/feed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/feed?search=Centru')->assertOk()->assertJsonCount(0, 'data');
    // Not "forbidden": the existence of the post is not disclosed.
    $this->getJson('/api/v1/posts/'.$post->id)->assertNotFound();
});

it('gives an attachment only to those who see its post', function () {
    Storage::fake('local');
    $o = $this->org;
    $source = tempnam(sys_get_temp_dir(), 'post');
    file_put_contents($source, 'plan');
    $post = app(ManagePosts::class)->create($o->a1, ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->centru->id]],
        [['source' => $source, 'name' => 'plan.txt', 'mime' => 'text/plain']]);
    $url = route('social.attachment', $post->attachments()->sole());

    $this->get($url)->assertRedirect();
    $this->actingAs($o->a2)->get($url)->assertOk()->assertDownload('plan.txt');
    $this->flushSession();
    $this->actingAs($o->b1)->get($url)->assertForbidden();
});

it('runs a group from its pages: creating, joining, requests, roles, the chat', function () {
    $o = $this->org;
    $this->actingAs($o->a1);
    Livewire::test(ListGroups::class)->callAction('create', data: ['name' => 'Voluntari Centru', 'type' => Group::CLOSED, 'description' => 'Echipa'])
        ->assertHasNoActionErrors();
    $group = Group::query()->sole();
    $secret = $this->social->group($o->a1, 'Grup secret', Group::SECRET);
    app(ManageGroups::class)->invite($o->a1, $secret, $o->b1->person);

    $this->flushSession();
    $this->actingAs($o->b1);
    Livewire::test(ListGroups::class)->assertCanSeeTableRecords([$group])->assertCanNotSeeTableRecords([$secret])
        ->assertSee(__('groups.ui.my_invitations'))
        ->callTableAction('join', $group);
    $this->get('/admin/groups/'.$group->id)->assertOk()->assertSee('Voluntari Centru')->assertDontSee(__('groups.ui.chat'));
    $this->get('/admin/groups/'.$secret->id)->assertNotFound();
    Livewire::test(ListGroups::class)->call('answerInvitation', app(ManageGroups::class)->invitationsFor($o->b1)->sole()->id, true);
    $this->get('/admin/groups/'.$secret->id)->assertOk()->assertSee('Grup secret');

    $this->flushSession();
    $this->actingAs($o->a1);
    $request = $group->fresh()->id;
    Livewire::test(ViewGroup::class, ['record' => $group->id])
        ->assertSee($o->b1->person->fullName())
        ->call('decide', GroupJoinRequest::query()->where('group_id', $request)->sole()->id, true)
        ->call('setRole', $o->b1->person_id, GroupMember::MODERATOR)
        ->callAction('invite', data: ['person_id' => $o->a2->person_id])
        ->callAction('edit', data: ['name' => 'Voluntari Centru', 'type' => Group::CLOSED, 'rules' => 'Fără spam'])
        ->assertHasNoActionErrors();
    Livewire::test(GroupDiscussion::class, ['groupId' => $group->id])->set('body', 'Salut, echipă')->call('post')->assertSee('Salut, echipă');

    $access = app(GroupAccess::class);
    $access->forget();
    expect($access->roleOf($group, $o->b1->person_id))->toBe(GroupMember::MODERATOR)
        ->and($group->fresh()->rules)->toBe('Fără spam')
        ->and(app(ManageGroups::class)->invitationsFor($o->a2)->count())->toBe(1);

    // A plain member cannot run the group through the page either.
    $this->flushSession();
    $this->actingAs($o->b1);
    Livewire::test(ViewGroup::class, ['record' => $group->id])->assertSee(__('groups.ui.chat'))->call('removeMember', $o->a1->person_id);
    $access->forget();
    expect($access->isMember($group, $o->a1->person_id))->toBeTrue();

    $this->flushSession();
    $this->actingAs($o->balti1);
    Livewire::test(GroupDiscussion::class, ['groupId' => $group->id])->assertNotFound();
});

it('admits by an invitation link after a confirmation', function () {
    $o = $this->org;
    $group = $this->social->group($o->a1, 'Închis', Group::CLOSED);
    ['token' => $token] = app(ManageGroups::class)->inviteByLink($o->a1, $group);

    $this->actingAs($o->b1)->get('/admin/groups/join/'.$token)->assertOk()->assertSee('Închis');
    expect(app(GroupAccess::class)->isMember($group, $o->b1->person_id))->toBeFalse();

    Livewire::test(GroupJoin::class, ['token' => $token])->call('join');
    app(GroupAccess::class)->forget();

    expect(app(GroupAccess::class)->isMember($group, $o->b1->person_id))->toBeTrue();
    $this->get('/admin/groups/join/wrong')->assertOk()->assertSee(__('groups.errors.invitation_unusable'));
});

it('shows the moderation queue by scope and applies the decisions', function () {
    $o = $this->org;
    $ofA = app(Moderation::class)->report($o->a2, $this->social->centruPost('Spam în Centru'), 'spam');
    $baltiPost = $this->social->post($o->balti1, 'Spam în Bălți', ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->baltiTerritory->id]]);
    $ofBalti = app(Moderation::class)->report($o->baltiHead, $baltiPost, 'spam');

    $this->actingAs($o->a1)->get('/admin/moderation')->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->social->moderator);
    $this->get('/admin/moderation')->assertOk()->assertSee('Spam în Centru')->assertDontSee('Spam în Bălți');
    Livewire::test(ListReports::class)->assertCanSeeTableRecords([$ofA])->assertCanNotSeeTableRecords([$ofBalti])
        ->callTableAction('mute', $ofA, data: ['until' => now()->addDay()->toDateTimeString(), 'reason' => 'Spam repetat'])
        ->callTableAction('hide', $ofA, data: ['reason' => 'Spam'])
        ->assertHasNoTableActionErrors();

    expect($ofA->fresh()->status)->toBe(ModerationReport::UPHELD)
        ->and(app(Moderation::class)->mutedUntil($o->a1->person_id))->not->toBeNull();

    $mute = ModerationAction::query()->where('action', ModerationAction::MUTE)->sole();
    Livewire::test(ListSanctions::class)->assertCanSeeTableRecords([$mute])
        ->callTableAction('unmute', $mute)
        ->callAction('warn', data: ['person_id' => $o->balti1->person_id, 'reason' => 'În afara regiunii']);

    expect(app(Moderation::class)->mutedUntil($o->a1->person_id))->toBeNull()
        ->and(ModerationAction::query()->where('action', ModerationAction::WARN)->count())->toBe(0);

    // A moderator of a group has a queue without any system role.
    $group = $this->social->group($o->b1, 'Grup', Group::OPEN, [$o->balti1]);
    $inGroup = $this->social->post($o->balti1, 'Spam în grup', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]);
    app(Moderation::class)->report($o->b1, $inGroup, 'spam');
    $this->flushSession();
    $this->actingAs($o->b1)->get('/admin/moderation')->assertOk()->assertSee('Spam în grup')->assertDontSee('Spam în Bălți');
});
