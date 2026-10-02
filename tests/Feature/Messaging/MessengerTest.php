<?php

use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\DirectMessagePolicy;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Notifications\MessageNotice;
use App\Domain\People\Actions\ManagePeople;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SocialFixture;

/*
 * ФО §6.6.1–6.6.3, ТЗ §20 — direct dialogs with the table of who may start them (Д-26), group chats with roles
 * and invitation links, threads, mentions, scheduled messages and drafts, editing and deleting.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->chats = app(ManageChats::class);
    $this->messages = app(SendMessages::class);
    $this->reader = app(ChatReader::class);
    $this->access = app(ChatAccess::class);
    $this->say = fn ($user, Chat $chat, string $body, array $data = []): Message => $this->messages->send($user, $chat, ['body' => $body, ...$data]);
});

it('starts one dialog per pair, and lets a candidate only answer', function () {
    $o = $this->org;
    $candidate = userWithRoles('candidate');
    $supporter = app(ManagePeople::class)->create($o->admin, ['first_name' => 'Fără', 'last_name' => 'Cont', 'person_type' => 'supporter', 'territory_id' => $o->centru->id]);

    $chat = $this->chats->direct($o->a1, $o->b1->person);

    expect($chat)->type->toBe(Chat::DIRECT)
        ->and($this->chats->direct($o->b1, $o->a1->person)->id)->toBe($chat->id)
        ->and(Chat::query()->count())->toBe(1)
        ->and($this->reader->title($o->a1, $chat))->toBe($o->b1->person->fullName())
        ->and($this->reader->title($o->b1, $chat))->toBe($o->a1->person->fullName())
        ->and(journalCount('messaging.chat.created'))->toBe(1)
        ->and(fn () => $this->chats->direct($o->a1, $o->a1->person))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->chats->direct($o->admin, $supporter))->toThrow(MessagingRuleViolation::class)
        // Д-26: a candidate starts no dialogs…
        ->and(fn () => $this->chats->direct($candidate, $o->a1->person))->toThrow(AuthorizationException::class);

    // …but answers in a dialog somebody else started with them.
    $withCandidate = $this->chats->direct($o->a1, $candidate->person);
    ($this->say)($o->a1, $withCandidate, 'Bună ziua! Vă așteptăm luni.');

    expect($this->chats->direct($candidate, $o->a1->person)->id)->toBe($withCandidate->id)
        ->and(($this->say)($candidate, $withCandidate, 'Mulțumesc, vin.')->id)->toBeInt()
        // A third person is not part of the dialog.
        ->and($this->access->mayRead($o->a2, $withCandidate))->toBeFalse()
        ->and(fn () => ($this->say)($o->a2, $withCandidate, 'X'))->toThrow(AuthorizationException::class);
});

it('follows the table of who may write first, changed only by the administrator', function () {
    $o = $this->org;
    $policy = app(DirectMessagePolicy::class);
    $role = fn (string $code): Role => Role::query()->where('code', $code)->sole();

    // Д-26: a volunteer writes to anyone they see — the head of the organization included.
    $volunteer = $o->member($o->branchA, ['volunteer' => ScopeType::Organization]);
    expect($policy->mayStart($volunteer, $o->orgHead))->toBeTrue()
        ->and($policy->mayStart(userWithRoles('candidate'), $o->a1))->toBeFalse();

    $policy->set($o->admin, $role('employee'), $role('org_head'), false);

    // The head of the organization is an employee too: the stricter of the rules about them wins.
    expect($policy->mayStart($o->a1, $o->orgHead))->toBeFalse()
        ->and($policy->mayStart($o->a1, $o->headA))->toBeTrue()
        ->and($policy->mayStart($o->orgHead, $o->a1))->toBeTrue()
        // A head of a unit holds a role that is still let through.
        ->and($policy->mayStart($o->headA, $o->orgHead))->toBeTrue()
        ->and(fn () => $this->chats->direct($o->a1, $o->orgHead->person))->toThrow(AuthorizationException::class)
        ->and(journalCount('messaging.policy.changed'))->toBe(1)
        ->and(fn () => $policy->set($o->orgHead, $role('employee'), $role('org_head'), true))->toThrow(AuthorizationException::class);

    // The head writes first — and the employee may answer.
    $chat = $this->chats->direct($o->orgHead, $o->a1->person);
    expect(($this->say)($o->a1, $chat, 'Am primit, mulțumesc.')->id)->toBeInt();

    $policy->set($o->admin, $role('candidate'), $role('employee'), true);
    expect($policy->mayStart(userWithRoles('candidate'), $o->a1))->toBeTrue();
});

it('tracks "sent, delivered, read" and counts what is unread', function () {
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);

    $first = ($this->say)($o->a1, $chat, ' Salut! ');
    $second = ($this->say)($o->a1, $chat, 'Ai un minut?');

    expect($first->body)->toBe('Salut!')
        ->and($this->reader->deliveryStatus($chat, $second))->toBe('sent')
        ->and($this->reader->unreadCounts($o->b1))->toBe([$chat->id => 2])
        ->and($this->reader->unreadTotal($o->a1))->toBe(0)
        ->and(fn () => ($this->say)($o->a1, $chat, '  '))->toThrow(MessagingRuleViolation::class);
    // One notice per chat until it is read: the second message is counted in the chat, not in the bell.
    $notice = $o->b1->notifications()->sole();
    expect($notice->getAttribute('category'))->toBe('messages')
        ->and(json_encode($notice->data, JSON_UNESCAPED_UNICODE))->toContain($o->a1->person->fullName())->not->toContain('Salut')
        ->and($o->a1->notifications()->count())->toBe(0);

    $this->messages->markDelivered($o->b1);
    $this->access->forget();
    expect($this->reader->deliveryStatus($chat, $second))->toBe('delivered')
        ->and($this->reader->unreadCounts($o->b1))->toBe([$chat->id => 2]);

    $this->messages->markRead($o->b1, $chat);
    $this->access->forget();
    expect($this->reader->deliveryStatus($chat, $second))->toBe('read')
        ->and($o->b1->unreadNotifications()->count())->toBe(0)
        ->and($this->reader->unreadTotal($o->b1))->toBe(0)
        ->and($this->reader->chats($o->b1)[0])->title->toBe($o->a1->person->fullName())->unread->toBe(0);
});

it('builds threads: any message can be a root, replies form a tree up to the configured depth', function () {
    config(['messaging.max_thread_depth' => 3]);
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, 'Sector Centru', [$o->a2->person_id, $o->headA->person_id]);
    $other = $this->chats->direct($o->a1, $o->b1->person);

    $question = ($this->say)($o->a1, $chat, 'Cine poate veni sâmbătă?');
    $unrelated = ($this->say)($o->headA, $chat, 'Raportul lunar e gata.');
    $answer = ($this->say)($o->a2, $chat, 'Eu pot.', ['parent_id' => $question->id]);
    $followUp = ($this->say)($o->a1, $chat, 'La ce oră?', ['parent_id' => $answer->id, 'quoted_message_id' => $answer->id]);
    $deep = ($this->say)($o->a2, $chat, 'La 9.', ['parent_id' => $followUp->id]);
    $deeper = ($this->say)($o->a1, $chat, 'Perfect.', ['parent_id' => $deep->id]);
    $second = ($this->say)($o->headA, $chat, 'Și eu vin.', ['parent_id' => $question->id]);
    $inOther = ($this->say)($o->a1, $other, 'Alt chat');

    $tree = $this->reader->messages($o->a2, $chat);

    expect($tree->pluck('id')->all())->toBe([$question->id, $answer->id, $followUp->id, $deep->id, $deeper->id, $second->id, $unrelated->id])
        ->and($tree->pluck('depth')->all())->toBe([0, 1, 2, 3, 3, 1, 0])
        ->and($deeper->root_id)->toBe($question->id)
        ->and($followUp->quoted->id)->toBe($answer->id)
        ->and($this->reader->thread($o->a2, $deep)->pluck('id')->all())->toBe([$question->id, $answer->id, $followUp->id, $deep->id, $deeper->id, $second->id])
        ->and($this->reader->threadDigest($o->a2, $question))->toContain('Cine poate veni sâmbătă?')->toContain('  '.$o->a2->person->fullName().': Eu pot.')
        ->and(fn () => ($this->say)($o->a1, $chat, 'X', ['parent_id' => $inOther->id]))->toThrow(MessagingRuleViolation::class)
        // A link to an old message opens its thread.
        ->and($this->reader->messages($o->a2, $chat, 1, $deep->id)->pluck('id')->all())->toContain($question->id, $deep->id, $unrelated->id);
});

it('runs a group chat: members, roles, leaving, invitation links', function () {
    Notification::fake();
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, ' Voluntari ', [$o->a2->person_id, $o->b1->person_id]);
    ($this->say)($o->a1, $chat, 'Bine ați venit');

    expect($chat)->title->toBe('Voluntari')->type->toBe(Chat::GROUP)
        ->and($this->access->roleOf($chat, $o->a1->person_id))->toBe(ChatMember::OWNER)
        ->and($this->access->mayRead($o->balti1, $chat))->toBeFalse()
        ->and($this->access->mayRead($o->admin, $chat))->toBeFalse()               // no role of the organization opens a chat
        ->and($this->reader->chats($o->balti1))->toBe([])
        ->and(fn () => $this->reader->messages($o->balti1, $chat))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->chats->createGroup($o->a1, ' '))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->chats->createGroup(userWithRoles('candidate'), 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->chats->addMembers($o->a2, $chat, [$o->balti1->person_id]))->toThrow(AuthorizationException::class);

    $this->chats->setRole($o->a1, $chat, $o->a2->person, ChatMember::ADMIN);
    $this->access->forget();

    expect($this->chats->addMembers($o->a2, $chat, [$o->balti1->person_id, $o->b1->person_id]))->toBe(1)
        // Someone who joins later does not get the history as "unread".
        ->and($this->reader->unreadTotal($o->balti1))->toBe(0)
        ->and(fn () => $this->chats->setRole($o->a2, $chat, $o->b1->person, ChatMember::ADMIN))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->chats->removeMember($o->a2, $chat, $o->a1->person))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->chats->leave($o->a1, $chat))->toThrow(MessagingRuleViolation::class);

    $this->chats->setRole($o->a2, $chat, $o->b1->person, ChatMember::MODERATOR);
    $this->chats->removeMember($o->a2, $chat, $o->balti1->person);
    $this->chats->leave($o->b1, $chat);
    $this->access->forget();

    expect($this->access->mayRead($o->balti1, $chat))->toBeFalse()
        ->and($this->access->mayRead($o->b1, $chat))->toBeFalse()
        ->and(journalCount('messaging.member.removed'))->toBe(2)
        ->and(journalCount('messaging.member.role_changed'))->toBe(2);

    ['invitation' => $invitation, 'token' => $token] = $this->chats->inviteByLink($o->a1, $chat, now()->addDay(), 1);

    expect($invitation->token_hash)->not->toBe($token)
        ->and($this->chats->joinByLink($o->b1, $token)->id)->toBe($chat->id)
        ->and(fn () => $this->chats->joinByLink($o->balti1, $token))->toThrow(MessagingRuleViolation::class)   // exhausted
        ->and(fn () => $this->chats->joinByLink($o->balti1, 'wrong'))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->chats->inviteByLink($o->b1, $chat))->toThrow(AuthorizationException::class);

    ['token' => $expiring, 'invitation' => $second] = $this->chats->inviteByLink($o->a1, $chat, now()->addHour());
    $this->chats->revokeLink($o->a1, $second);
    expect(fn () => $this->chats->joinByLink($o->balti1, $expiring))->toThrow(MessagingRuleViolation::class);

    ['token' => $short] = $this->chats->inviteByLink($o->a1, $chat, now()->addHour());
    $this->travel(2)->hours();
    expect(fn () => $this->chats->joinByLink($o->balti1, $short))->toThrow(MessagingRuleViolation::class)
        ->and(journalCount('messaging.link.created'))->toBe(3);
});

it('notifies by the member\'s own choice: all messages, mentions only, or nothing', function () {
    Notification::fake();
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, 'Sector Centru', [$o->a2->person_id, $o->headA->person_id, $o->b1->person_id]);
    $this->chats->setNotify($o->a2, $chat, ChatMember::NOTIFY_MENTIONS);
    $this->chats->setNotify($o->b1, $chat, ChatMember::NOTIFY_MUTE);

    ($this->say)($o->a1, $chat, 'Mesaj obișnuit');
    Notification::assertSentTo($o->headA, MessageNotice::class, fn (MessageNotice $n) => $n->kind === MessageNotice::MESSAGE && $n->category() === 'messages');
    Notification::assertNotSentTo($o->a2, MessageNotice::class);
    Notification::assertNotSentTo($o->b1, MessageNotice::class);

    // A mention reaches those who asked for mentions; "mute" silences even that; outsiders cannot be mentioned.
    $mention = ($this->say)($o->a1, $chat, 'Maria, te rog', ['mention_person_ids' => [$o->a2->person_id, $o->b1->person_id, $o->balti1->person_id]]);
    Notification::assertSentTo($o->a2, MessageNotice::class, fn (MessageNotice $n) => $n->kind === MessageNotice::MENTION && $n->category() === 'mentions');
    Notification::assertNotSentTo($o->b1, MessageNotice::class);
    Notification::assertNotSentTo($o->balti1, MessageNotice::class);

    expect($mention->mentioned()->pluck('people.id')->all())->toEqualCanonicalizing([$o->a2->person_id, $o->b1->person_id])
        // "@all": the one who runs the chat, or a head by the right of their role — not a plain member.
        ->and(($this->say)($o->a1, $chat, 'Către toți', ['mention_all' => true])->mentions_all)->toBeTrue()
        ->and(($this->say)($o->headA, $chat, 'Atenție', ['mention_all' => true])->mentions_all)->toBeTrue()
        ->and(fn () => ($this->say)($o->a2, $chat, 'Toți!', ['mention_all' => true]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->chats->setNotify($o->a2, $chat, 'loud'))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->chats->setNotify($o->balti1, $chat, ChatMember::NOTIFY_MUTE))->toThrow(AuthorizationException::class);
    Notification::assertSentToTimes($o->a2, MessageNotice::class, 3);
});

it('keeps a draft on the server and sends a scheduled message when its time comes', function () {
    Notification::fake();
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);

    $this->messages->saveDraft($o->a1, $chat, 'Încep să scriu…');
    $this->access->forget();
    expect($this->access->membership($chat, $o->a1->person_id)->draft)->toBe('Încep să scriu…');

    $scheduled = ($this->say)($o->a1, $chat, 'La mulți ani!', ['send_at' => now()->addHours(2)]);
    $now = ($this->say)($o->b1, $chat, 'Scriu acum');
    $this->access->forget();

    expect($scheduled->status)->toBe(Message::SCHEDULED)
        ->and($this->access->membership($chat, $o->a1->person_id)->draft)->toBeNull()
        ->and($this->reader->messages($o->a1, $chat)->pluck('id')->all())->toBe([$scheduled->id, $now->id])
        ->and($this->reader->messages($o->b1, $chat)->pluck('id')->all())->toBe([$now->id])
        ->and($this->reader->search($o->b1, 'mulți')->count())->toBe(0)
        ->and($this->messages->sendScheduled())->toBe(0)
        ->and(fn () => ($this->say)($o->a1, $chat, 'X', ['send_at' => now()->subMinute()]))->toThrow(MessagingRuleViolation::class);
    Notification::assertNotSentTo($o->b1, MessageNotice::class);

    $this->travel(3)->hours();

    expect($this->messages->sendScheduled())->toBe(1)
        ->and(Message::query()->whereKey($scheduled->id)->exists())->toBeFalse();
    $sent = Message::query()->where('body', 'La mulți ani!')->sole();
    // It takes its place by the moment it was sent, not by the moment it was typed.
    expect($sent->id)->toBeGreaterThan($now->id)
        ->and($sent->status)->toBe(Message::SENT)
        ->and($this->reader->unreadCounts($o->b1))->toBe([$chat->id => 1]);
    Notification::assertSentTo($o->b1, MessageNotice::class);
    $this->artisan('messaging:tick')->expectsOutputToContain('Messages sent: 0')->assertSuccessful();
});

it('lets the author edit and delete, and a moderator of the chat delete with a journal entry', function () {
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, 'Voluntari', [$o->a2->person_id, $o->b1->person_id]);
    $this->chats->setRole($o->a1, $chat, $o->b1->person, ChatMember::MODERATOR);
    $this->access->forget();
    $own = ($this->say)($o->a2, $chat, 'Prima versiune');
    $rude = ($this->say)($o->a2, $chat, 'Ceva nepotrivit');

    $this->messages->edit($o->a2, $own, 'A doua versiune');

    expect($own->fresh())->body->toBe('A doua versiune')->edited_at->not->toBeNull()
        ->and(fn () => $this->messages->edit($o->a1, $own->fresh(), 'Al altcuiva'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->edit($o->a2, $own->fresh(), ' '))->toThrow(MessagingRuleViolation::class);

    $this->messages->delete($o->a2, $own->fresh());
    $this->messages->delete($o->b1, $rude);

    expect($own->fresh()->isDeleted())->toBeTrue()
        ->and($rude->fresh()->deleted_by_user_id)->toBe($o->b1->id)
        // Only a deletion of somebody else's words is journaled.
        ->and(journalCount('messaging.message.deleted'))->toBe(1)
        ->and(fn () => $this->messages->delete($o->a2, ($this->say)($o->a1, $chat, 'Al proprietarului')))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->edit($o->a2, $own->fresh(), 'După ștergere'))->toThrow(AuthorizationException::class)
        // The mark stays in its place in the thread; the search no longer finds the text.
        ->and($this->reader->messages($o->a1, $chat)->pluck('id')->all())->toContain($own->id, $rude->id)
        ->and($this->reader->search($o->a1, 'nepotrivit')->count())->toBe(0);
});
