<?php

use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\DirectMessagePolicy;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Retention;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\TaskWorkflow;
use App\Filament\Pages\ChatInvestigation;
use App\Filament\Pages\MessagingPolicies;
use App\Filament\Pages\Messenger;
use App\Filament\Pages\MessengerJoin;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\SocialFixture;

/*
 * The messenger page is a thin layer: it shows the chats the person is part of and calls the actions of the
 * Messaging module. These tests go through it the way a person does.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->chats = app(ManageChats::class);
    $this->messages = app(SendMessages::class);
    $this->say = fn ($user, Chat $chat, string $body, array $data = []): Message => $this->messages->send($user, $chat, ['body' => $body, ...$data]);
});

it('shows a person their chats and nothing of a chat they are not part of', function () {
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, 'Conducerea filialei', [$o->headA->person_id]);
    ($this->say)($o->a1, $chat, 'Bugetul pe trimestru');

    $this->actingAs($o->headA);
    $this->get('/admin/messenger')->assertOk()->assertSee('Conducerea filialei');
    $this->get('/admin/messenger?chat='.$chat->id)->assertOk()->assertSee('Bugetul pe trimestru');
    // Opening the chat is reading it.
    expect(app(ChatReader::class)->unreadTotal($o->headA))->toBe(0);

    $this->flushSession();
    $this->actingAs($o->a2);
    $this->get('/admin/messenger')->assertOk()->assertDontSee('Conducerea filialei');
    // The id of somebody's chat in the address opens nothing.
    $this->get('/admin/messenger?chat='.$chat->id)->assertOk()->assertDontSee('Bugetul pe trimestru')->assertSee(__('messaging.ui.choose_chat'));
    Livewire::test(Messenger::class, ['chatId' => $chat->id])->set('body', 'Încerc să scriu')->call('send')
        ->set('search', 'Bugetul')->assertDontSee('Bugetul pe trimestru');

    expect(Message::query()->count())->toBe(1);
});

it('writes, replies, edits and finds messages from the page', function () {
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);
    $first = ($this->say)($o->b1, $chat, 'Unde ne întâlnim?');

    $this->actingAs($o->a1);
    Livewire::test(Messenger::class, ['chatId' => $chat->id])
        ->set('body', 'Începutul unui răspuns')          // left unsent: it is a draft now
        ->assertSet('body', 'Începutul unui răspuns');
    app(ChatAccess::class)->forget();
    expect(app(ChatAccess::class)->membership($chat, $o->a1->person_id)->draft)->toBe('Începutul unui răspuns');

    Livewire::test(Messenger::class, ['chatId' => $chat->id])
        ->assertSet('body', 'Începutul unui răspuns')
        ->call('reply', $first->id)
        ->set('body', 'La sediu, la 18:00')
        ->call('send')
        ->assertSet('body', '')->assertSet('replyTo', null)
        ->assertSee('La sediu, la 18:00')
        ->call('react', $first->id, 'like')
        ->set('search', 'sediu')->assertSee(__('messaging.ui.found'));
    $reply = Message::query()->where('body', 'La sediu, la 18:00')->sole();

    Livewire::test(Messenger::class, ['chatId' => $chat->id])
        ->callAction('edit', data: ['body' => 'La sediu, la 18:30'], arguments: ['message' => $reply->id])
        ->call('pin', $reply->id, true)
        ->assertHasNoActionErrors()
        ->assertSee('La sediu, la 18:30')->assertSee(__('messaging.ui.edited'));

    expect($reply->fresh())->parent_id->toBe($first->id)->body->toBe('La sediu, la 18:30')
        ->and($reply->fresh()->pinned_at)->not->toBeNull()
        ->and($first->reactions()->count())->toBe(1);

    // The other side sees the status of their message and cannot edit somebody else's through the page.
    $this->flushSession();
    $this->actingAs($o->b1);
    Livewire::test(Messenger::class, ['chatId' => $chat->id])->assertSee(__('messaging.ui.delivery.read'))
        ->callAction('edit', data: ['body' => 'Al altcuiva'], arguments: ['message' => $reply->id])
        ->call('deleteMessage', $reply->id);
    expect($reply->fresh())->body->toBe('La sediu, la 18:30')->deleted_at->toBeNull();
});

it('starts dialogs and group chats, sends files, polls and scheduled messages, forwards and makes tasks', function () {
    Storage::fake('local');
    TaskWorkflow::ensureDefaults();
    $o = $this->org;
    $candidate = userWithRoles('candidate');

    $this->actingAs($o->headA);
    Livewire::test(Messenger::class)
        ->callAction('dialog', data: ['person_id' => $o->a1->person_id])
        ->callAction('group', data: ['title' => 'Filiala A', 'member_ids' => [$o->a1->person_id, $o->a2->person_id]])
        ->assertHasNoActionErrors();
    $group = Chat::query()->where('type', Chat::GROUP)->sole();
    $direct = Chat::query()->where('type', Chat::DIRECT)->sole();

    Livewire::test(Messenger::class, ['chatId' => $group->id])
        ->callAction('compose', data: [
            'body' => 'Când facem ședința?', 'poll_options' => ['Luni', 'Marți'], 'mention_person_ids' => [$o->a1->person_id], 'mention_all' => true,
        ])
        ->callAction('compose', data: ['body' => 'Amintire pentru mâine', 'send_at' => now()->addDay()->toDateTimeString()])
        ->callAction('rename', data: ['title' => 'Filiala A — operativ'])
        ->callAction('link', data: ['max_uses' => 2])
        ->call('setNotify', 'mentions')
        ->assertHasNoActionErrors()
        ->assertSee('Când facem ședința?')->assertSee(__('messaging.ui.cancel_scheduled'));
    $poll = Message::query()->where('kind', Message::POLL)->sole();

    Livewire::test(Messenger::class, ['chatId' => $group->id])
        ->call('vote', $poll->id, $poll->pollOptions()->firstOrFail()->id)
        ->callAction('forward', data: ['chat_id' => $direct->id, 'comment' => 'Vezi sondajul'], arguments: ['message' => $poll->id])
        ->callAction('task', data: ['title' => 'Organizarea ședinței', 'type_code' => 'assignment', 'assignees' => [$o->a1->person_id]], arguments: ['message' => $poll->id])
        ->assertHasNoActionErrors();

    expect($group->fresh()->title)->toBe('Filiala A — operativ')
        ->and($poll)->mentions_all->toBeTrue()
        ->and($poll->mentioned()->count())->toBe(1)
        ->and(Message::query()->where('status', Message::SCHEDULED)->count())->toBe(1)
        ->and(Message::query()->where('chat_id', $direct->id)->sole()->forwarded_from_message_id)->toBe($poll->id)
        ->and(Task::query()->where('title', 'Organizarea ședinței')->sole()->id)->toBe($poll->fresh()->task_id)
        ->and(app(ChatAccess::class)->membership($group, $o->headA->person_id)->notify)->toBe(ChatMember::NOTIFY_MENTIONS);

    // The recipient of the forward is a member of the group too, so the forwarded poll question is shown to them.
    $this->flushSession();
    $this->actingAs($o->a1);
    $this->get('/admin/messenger?chat='.$direct->id)->assertOk()->assertSee('Vezi sondajul')->assertSee('Când facem ședința?');

    // Д-26 on the page: a candidate cannot start a dialog — nothing is created.
    $this->flushSession();
    $this->actingAs($candidate);
    $before = Chat::query()->count();
    Livewire::test(Messenger::class)->callAction('dialog', data: ['person_id' => $o->a1->person_id]);
    expect(Chat::query()->count())->toBe($before);
});

it('serves an attachment to the members of the chat only', function () {
    Storage::fake('local');
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);
    $source = tempnam(sys_get_temp_dir(), 'msg');
    file_put_contents($source, 'conținut');
    $attachment = $this->messages->send($o->a1, $chat, [], [['source' => $source, 'name' => 'lista.txt', 'mime' => 'text/plain']])->attachments()->sole();
    $url = route('messenger.attachment', $attachment);

    $this->get($url)->assertRedirect();
    $this->actingAs($o->b1)->get($url)->assertOk()->assertDownload('lista.txt');
    $this->get('/admin/messenger?chat='.$chat->id)->assertOk()->assertSee('lista.txt');
    $this->flushSession();
    $this->actingAs($o->a2)->get($url)->assertForbidden();
});

it('joins a group chat by a link after a confirmation, and manages its members from the page', function () {
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, 'Voluntari', [$o->a2->person_id]);
    ['token' => $token] = $this->chats->inviteByLink($o->a1, $chat);

    $this->actingAs($o->b1)->get('/admin/messenger/join/'.$token)->assertOk()->assertSee('Voluntari');
    expect(app(ChatAccess::class)->mayRead($o->b1, $chat))->toBeFalse();
    Livewire::test(MessengerJoin::class, ['token' => $token])->call('join');
    app(ChatAccess::class)->forget();
    expect(app(ChatAccess::class)->mayRead($o->b1, $chat))->toBeTrue();
    $this->get('/admin/messenger/join/wrong')->assertOk()->assertSee(__('messaging.errors.link_unusable'));

    // A plain member manages nothing through the page.
    Livewire::test(Messenger::class, ['chatId' => $chat->id])->call('removeMember', $o->a2->person_id)->call('setRole', $o->a2->person_id, ChatMember::ADMIN);
    app(ChatAccess::class)->forget();
    expect(app(ChatAccess::class)->roleOf($chat, $o->a2->person_id))->toBe(ChatMember::MEMBER);

    $this->flushSession();
    $this->actingAs($o->a1);
    Livewire::test(Messenger::class, ['chatId' => $chat->id])
        ->callAction('addMembers', data: ['member_ids' => [$o->headA->person_id]])
        ->call('setRole', $o->a2->person_id, ChatMember::MODERATOR)
        ->call('removeMember', $o->b1->person_id)
        ->set('showMembers', true)->assertSee($o->headA->person->fullName());
    app(ChatAccess::class)->forget();
    expect(app(ChatAccess::class)->roleOf($chat, $o->a2->person_id))->toBe(ChatMember::MODERATOR)
        ->and(app(ChatAccess::class)->mayRead($o->b1, $chat))->toBeFalse()
        ->and(app(ChatAccess::class)->mayRead($o->headA, $chat))->toBeTrue();

    $this->flushSession();
    $this->actingAs($o->a2);
    Livewire::test(Messenger::class, ['chatId' => $chat->id])->call('leave')->assertSet('chatId', null);
});

it('lets only the administrator keep the rules of the messenger', function () {
    $o = $this->org;
    $employee = Role::query()->where('code', 'employee')->sole();
    $head = Role::query()->where('code', 'org_head')->sole();

    $this->actingAs($o->orgHead)->get('/admin/messaging-policies')->assertForbidden();

    $this->flushSession();
    $this->actingAs($o->admin);
    $this->get('/admin/messaging-policies')->assertOk()->assertSee(__('messaging.ui.direct_rules'));
    Livewire::test(MessagingPolicies::class)->call('toggle', $employee->id, $head->id)->set('retentionMonths', 24)->call('saveRetention');

    app(DirectMessagePolicy::class)->forget();
    expect(app(DirectMessagePolicy::class)->allowed($employee->id, $head->id))->toBeFalse()
        ->and(app(Retention::class)->months())->toBe(24);

    Livewire::test(MessagingPolicies::class)->set('retentionMonths', null)->call('saveRetention');
    expect(app(Retention::class)->months())->toBeNull();
});

it('opens somebody\'s chat for an investigation only with the reserved right, a reason and a journal entry', function () {
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);
    ($this->say)($o->a1, $chat, 'Text privat');

    // Nobody holds the right by default — not even the super admin.
    $this->actingAs($o->admin)->get('/admin/chat-investigation')->assertForbidden();

    // The reserved right is added to a role on purpose (and journaled as such); its holders get it.
    $investigator = $o->member($o->central, ['security' => ScopeType::Organization]);
    $role = Role::query()->where('code', 'security')->sole();
    app(SetRolePermissions::class)($o->admin, $role, [...$role->permissions()->pluck('permission_code')->all(), 'chats.read.investigation']);
    app(AuthorizationService::class)->forget();

    $this->flushSession();
    $this->actingAs($investigator);
    Livewire::test(ChatInvestigation::class)
        ->callAction('start', data: ['person_id' => $o->a1->person_id, 'reason' => 'Plângere nr. 12'])
        ->assertHasNoActionErrors()
        ->assertSee($o->b1->person->fullName())
        ->call('read', $chat->id)
        ->assertSee('Text privat')
        // Not a chat from the list — nothing opens.
        ->call('read', 999999)->assertSet('openChatId', $chat->id);

    expect(journalCount('messaging.chat.investigated'))->toBeGreaterThanOrEqual(2)
        ->and(journalCount('access.reserved_permission.granted'))->toBe(1)
        // The investigator still is not a member: the messenger itself shows them nothing.
        ->and(app(ChatReader::class)->chats($investigator))->toBe([]);
});
