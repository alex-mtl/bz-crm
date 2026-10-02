<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Files\AntivirusProtection;
use App\Domain\Files\Exceptions\AntivirusUnavailable;
use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\DirectMessagePolicy;
use App\Domain\Messaging\Discussions;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatInvitation;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageAttachment;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\Messenger;
use Database\Seeders\Demo\MessagingDemoSeeder as Chats;
use Database\Seeders\Demo\Personas;
use Database\Seeders\Demo\SocialDemoSeeder as Social;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * IMPLEMENTATION-PLAN 6.3 — the messenger on the demo world (docs/demo/README.md § Мессенджер).
 */

function messenger(): ChatAccess
{
    app(AuthorizationService::class)->forget();
    app(ChatAccess::class)->forget();

    return app(ChatAccess::class);
}

function toldInChats(string $persona): string
{
    return Personas::user($persona)->notifications()->get()->map(fn ($n): string => json_encode($n->data, JSON_UNESCAPED_UNICODE))->implode("\n");
}

it('does not create a direct dialog the table forbids — and still lets the person answer', function () {
    $chats = app(ManageChats::class);
    $policy = app(DirectMessagePolicy::class);
    $radu = Personas::user('volunteer');
    $alexandru = Personas::user('security');

    // The rule of the demo world: a volunteer does not write first to the security service.
    $existing = Chats::direct('security', 'volunteer');
    expect($policy->mayStart($radu, $alexandru))->toBeFalse()
        ->and($policy->mayStart($alexandru, $radu))->toBeTrue()
        // The dialog exists because the security officer wrote first; the volunteer answers in it.
        ->and($chats->direct($radu, $alexandru->person)->id)->toBe($existing->id)
        ->and(app(SendMessages::class)->send($radu, $existing, ['body' => 'Am verificat încă o dată.'])->id)->toBeInt();

    // Without that dialog nothing is created for him.
    $existing->delete();
    messenger();
    expect(fn () => $chats->direct($radu, $alexandru->person))->toThrow(AuthorizationException::class)
        ->and(Chat::query()->where('direct_key', Chat::directKey($radu->person_id, $alexandru->person_id))->exists())->toBeFalse()
        // Д-26: a volunteer writes to anyone he sees — his own chain of command included…
        ->and($chats->direct($radu, Personas::user('branch_a_head')->person)->id)->toBe(Chats::direct('volunteer', 'branch_a_head')->id)
        ->and($chats->direct($radu, Personas::user('chisinau_head')->person)->type)->toBe(Chat::DIRECT)
        // …and to nobody he does not see: the head of another branch is not in his directory.
        ->and(fn () => $chats->direct($radu, Personas::user('branch_b_head')->person))->toThrow(AuthorizationException::class);

    // A candidate starts no dialogs at all, but answers when written to.
    $candidate = userWithRoles('candidate');
    expect(fn () => $chats->direct($candidate, Personas::user('branch_a_employee_1')->person))->toThrow(AuthorizationException::class);
    $started = $chats->direct(Personas::user('hr'), $candidate->person);
    messenger();
    expect(app(SendMessages::class)->send($candidate, $started, ['body' => 'Bună ziua!'])->id)->toBeInt();
});

it('finds no message of a chat the person is not part of', function () {
    $reader = app(ChatReader::class);
    $ion = Personas::user('branch_a_employee_1');

    expect($reader->search($ion, 'Bugetul')->count())->toBe(0)                                 // the chat of the heads
        ->and($reader->search(Personas::user('branch_a_head'), 'Bugetul')->count())->toBe(1)
        ->and($reader->search($ion, 'microbuz')->count())->toBeGreaterThan(1)                  // his own chats
        ->and($reader->search(Personas::user('balti_employee_1'), 'microbuz')->count())->toBe(0)
        ->and($reader->search(Personas::user('super_admin'), 'microbuz')->count())->toBe(0)    // no role opens a chat
        ->and($reader->search($ion, 'microbuz', Chats::chat(Chats::HEADS))->count())->toBe(0);

    $this->actingAs($ion);
    Livewire::test(Messenger::class)->set('search', 'Bugetul')->assertDontSee(Chats::BUDGET);
    Livewire::test(Messenger::class, ['chatId' => Chats::chat(Chats::HEADS)->id])->assertSee(__('messaging.ui.choose_chat'))->assertDontSee(Chats::BUDGET);
    $this->get('/admin/messenger')->assertOk()->assertDontSee(Chats::HEADS)->assertSee(Chats::LOGISTICS);
});

it('forwards out of a closed chat without disclosing the message to those who were not in it', function () {
    $reader = app(ChatReader::class);
    $branch = Chats::chat(Chats::BRANCH_A);
    $forward = Message::query()->where('chat_id', $branch->id)->whereNotNull('forwarded_from_message_id')->sole();

    expect($forward->forwardedFrom->body)->toStartWith(Chats::BUDGET)
        ->and($forward->body)->not->toContain(Chats::BUDGET)
        ->and($reader->seesOriginal(Personas::user('branch_a_head'), $forward))->toBeTrue()
        ->and($reader->seesOriginal(Personas::user('branch_a_employee_1'), $forward))->toBeFalse()
        ->and(toldInChats('branch_a_employee_1'))->not->toContain(Chats::BUDGET)
        // Nor can he pass on what he does not see.
        ->and(fn () => app(SendMessages::class)->forward(Personas::user('branch_a_employee_1'), $forward, Chats::chat(Chats::LOGISTICS)))
        ->toThrow(AuthorizationException::class);

    $this->actingAs(Personas::user('branch_a_employee_1'))->get('/admin/messenger?chat='.$branch->id)->assertOk()
        ->assertSee(__('messaging.ui.forwarded_hidden'))->assertDontSee(Chats::BUDGET);
    $this->flushSession();
    $this->actingAs(Personas::user('branch_a_head'))->get('/admin/messenger?chat='.$branch->id)->assertOk()->assertSee(Chats::BUDGET);
});

it('keeps "@all" for those who run the chat or hold the right', function () {
    $messages = app(SendMessages::class);
    $logistics = Chats::chat(Chats::LOGISTICS);
    $all = fn (string $persona, Chat $chat) => fn () => $messages->send(Personas::user($persona), $chat, ['body' => 'Atenție tuturor', 'mention_all' => true]);

    expect($all('branch_a_employee_3', $logistics))->toThrow(AuthorizationException::class)      // a plain member
        ->and($all('volunteer', $logistics))->toThrow(AuthorizationException::class)
        ->and($all('branch_b_employee_1', $logistics))->toThrow(AuthorizationException::class)   // a moderator moderates, not more
        ->and($all('branch_a_employee_2', $logistics)()->mentions_all)->toBeTrue()               // the owner
        ->and($all('branch_a_employee_1', $logistics)()->mentions_all)->toBeTrue()               // an admin of the chat
        ->and($all('branch_a_head', Chats::chat(Chats::BRANCH_A))()->mentions_all)->toBeTrue()
        // A head has the right by role — but only in a chat he is part of.
        ->and($all('chisinau_head', $logistics))->toThrow(AuthorizationException::class);
});

it('shows every state of reading, a draft and a scheduled message', function () {
    $reader = app(ChatReader::class);
    $last = fn (Chat $chat): Message => Message::query()->where('chat_id', $chat->id)->where('status', Message::SENT)->orderByDesc('id')->firstOrFail();
    $read = Chats::direct('branch_a_employee_1', 'branch_a_employee_2');
    $delivered = Chats::direct('branch_a_head', 'branch_a_employee_3');
    $sent = Chats::direct('chisinau_head', 'branch_b_head');
    $withDraft = Chats::direct('branch_a_employee_1', 'branch_a_head');

    expect($reader->deliveryStatus($read, $last($read)))->toBe('read')
        ->and($reader->deliveryStatus($delivered, $last($delivered)))->toBe('delivered')
        ->and($reader->deliveryStatus($sent, $last($sent)))->toBe('sent')
        ->and($reader->unreadCounts(Personas::user('branch_b_head')))->toHaveKey($sent->id)
        ->and(messenger()->membership($withDraft, Personas::user('branch_a_employee_1')->person_id)->draft)->toStartWith(Chats::DRAFT);

    // Ana's message for tomorrow morning: she sees it waiting, Ion sees nothing yet.
    $scheduled = Message::query()->where('chat_id', $withDraft->id)->where('status', Message::SCHEDULED)->sole();
    expect($reader->messages(Personas::user('branch_a_head'), $withDraft)->pluck('id')->all())->toContain($scheduled->id)
        ->and($reader->messages(Personas::user('branch_a_employee_1'), $withDraft)->pluck('id')->all())->not->toContain($scheduled->id)
        ->and($reader->search(Personas::user('branch_a_employee_1'), 'ridicăm materialele')->count())->toBe(0);

    $this->travelTo($scheduled->send_at->copy()->addMinutes(5));
    $this->artisan('messaging:tick')->expectsOutputToContain('Messages sent: 1')->assertSuccessful();
    $this->artisan('messaging:tick')->expectsOutputToContain('Messages sent: 0')->assertSuccessful();

    expect($reader->search(Personas::user('branch_a_employee_1'), 'ridicăm materialele')->count())->toBe(1)
        ->and($reader->unreadCounts(Personas::user('branch_a_employee_1'))[$withDraft->id])->toBeGreaterThan(0);
});

it('has a task chat with a thread four levels deep and a task made from it', function () {
    $reader = app(ChatReader::class);
    $root = Message::query()->where('body', Chats::THREAD_ROOT)->sole();
    $thread = $reader->thread(Personas::user('branch_a_head'), $root);
    $task = Task::query()->where('title', Chats::TASK_FROM_THREAD)->sole();

    expect($root->chat->isSubject())->toBeTrue()
        ->and($thread->max('depth'))->toBe(4)
        ->and($thread->whereNotNull('quoted_message_id')->count())->toBe(1)
        ->and($root->task_id)->toBe($task->id)
        ->and($thread->where('kind', Message::SYSTEM)->sole()->body)->toContain(Chats::TASK_FROM_THREAD)
        // The task starts with the discussion it came from.
        ->and($task->description)->toContain(Chats::THREAD_ROOT)->toContain('În stoc sunt 300')
        // The discussion follows the task: the assignees and the head read it, another branch does not.
        ->and(messenger()->mayRead(Personas::user('volunteer'), $root->chat))->toBeTrue()
        ->and(messenger()->mayRead(Personas::user('branch_b_employee_1'), $root->chat))->toBeFalse()
        ->and(fn () => $reader->thread(Personas::user('branch_b_employee_1'), $root))->toThrow(AuthorizationException::class);

    $this->actingAs(Personas::user('branch_a_employee_1'))->get('/admin/messenger?chat='.$root->chat_id.'&message='.$root->id)->assertOk()
        ->assertSee(Chats::THREAD_ROOT)->assertSee('În stoc sunt 300')->assertSee(__('messaging.ui.open_task'));
});

it('opens the chats of a group and of a project to those who may read the group and the project', function () {
    $discussions = app(Discussions::class);
    $projectChat = $discussions->findFor(Project::query()->where('name', Chats::CAMPAIGN)->sole());
    $groupChat = $discussions->findFor(Social::group(Social::GROUP_OPEN));
    $secretChat = $discussions->findFor(Social::group(Social::GROUP_SECRET));
    $titles = fn (string $persona): array => collect(app(ChatReader::class)->chats(Personas::user($persona)))->pluck('title')->all();

    expect(messenger()->mayRead(Personas::user('volunteer'), $projectChat))->toBeTrue()            // a member of the project
        ->and(messenger()->mayRead(Personas::user('branch_b_employee_1'), $projectChat))->toBeFalse()
        ->and(messenger()->mayRead(Personas::user('branch_b_employee_1'), $groupChat))->toBeTrue()  // Olga is in the open group
        ->and(messenger()->mayRead(Personas::user('balti_employee_1'), $groupChat))->toBeFalse()
        // The chat of the secret group does not exist for anyone outside it — the super admin included.
        ->and(messenger()->mayRead(Personas::user('super_admin'), $secretChat))->toBeFalse()
        ->and(messenger()->mayRead(Personas::user('chisinau_head'), $secretChat))->toBeTrue();
    messenger();
    expect($titles('branch_a_employee_3'))->toContain(Chats::CAMPAIGN, Social::GROUP_OPEN)->not->toContain(Social::GROUP_SECRET, Chats::HEADS)
        ->and($groupChat->messages()->where('depth', 1)->count())->toBe(1);
});

it('has a group chat with roles, a pin, a poll, mentions, files and messages deleted with a mark', function () {
    $chat = Chats::chat(Chats::LOGISTICS);
    $access = messenger();
    $role = fn (string $persona): ?string => $access->roleOf($chat, Personas::user($persona)->person_id);
    $messages = Message::query()->where('chat_id', $chat->id)->get();
    $deleted = $messages->whereNotNull('deleted_at');

    expect($role('branch_a_employee_2'))->toBe(ChatMember::OWNER)
        ->and($role('branch_a_employee_1'))->toBe(ChatMember::ADMIN)
        ->and($role('branch_b_employee_1'))->toBe(ChatMember::MODERATOR)
        ->and($role('central_employee'))->toBe(ChatMember::MEMBER)                   // came by the single-use link
        ->and($messages->whereNotNull('pinned_at'))->toHaveCount(1)
        ->and($messages->where('kind', Message::POLL)->sole()->pollOptions()->count())->toBe(3)
        ->and($messages->where('mentions_all', true))->toHaveCount(1)
        ->and($deleted)->toHaveCount(2)
        // Only the deletion by a moderator is journaled — the author's own is not.
        ->and(JournalEntry::query()->where('event_type', 'messaging.message.deleted')->count())->toBe(1)
        ->and(MessageAttachment::query()->whereIn('message_id', $messages->pluck('id'))->pluck('kind')->all())->toEqualCanonicalizing(['file', 'image']);

    // Links: used up, expired, still good.
    $links = ChatInvitation::query()->where('chat_id', $chat->id)->get();
    expect($links->filter->isExhausted())->toHaveCount(1)
        ->and($links->filter->isExpired())->toHaveCount(1)
        ->and($links->filter->isUsable())->toHaveCount(1);

    $attachment = MessageAttachment::query()->whereIn('message_id', $messages->pluck('id'))->where('kind', 'file')->sole();
    $this->actingAs(Personas::user('volunteer'))->get(route('messenger.attachment', $attachment))->assertOk();
    $this->get('/admin/messenger?chat='.$chat->id)->assertOk()->assertSee(__('messaging.ui.deleted'))->assertDontSee('bilet la meci');
    $this->flushSession();
    $this->actingAs(Personas::user('balti_employee_1'))->get(route('messenger.attachment', $attachment))->assertForbidden();
    expect(fn () => app(ManageChats::class)->joinByLink(Personas::user('balti_employee_1'), 'not-a-token'))->toThrow(MessagingRuleViolation::class);
});

it('lets the WebSocket channel of a chat be joined by its members only', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]]);
    require base_path('routes/channels.php');
    $heads = Chats::chat(Chats::HEADS);
    $join = fn (string $persona, string $channel) => $this->actingAs(Personas::user($persona))
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $join('branch_a_head', 'private-chat.'.$heads->id)->assertOk();
    $this->flushSession();
    $join('branch_a_employee_1', 'private-chat.'.$heads->id)->assertForbidden();
    $join('branch_a_employee_1', 'private-messenger.'.Personas::user('branch_a_head')->id)->assertForbidden();
    $this->flushSession();
    $join('super_admin', 'private-chat.'.$heads->id)->assertForbidden();
});

it('keeps the antivirus check off in the demo world, with the switch in the hands of the super admin only', function () {
    $protection = app(AntivirusProtection::class);

    // Д-28: off while the platform is developed and tested — the files of the demo world went in unchecked.
    expect($protection->enabled())->toBeFalse()
        ->and(MessageAttachment::query()->pluck('scan_status')->unique()->all())->toBe([MessageAttachment::SKIPPED])
        ->and(fn () => $protection->set(Personas::user('security'), true))->toThrow(AuthorizationException::class)
        ->and(fn () => $protection->set(Personas::user('org_head'), true))->toThrow(AuthorizationException::class)
        // No antivirus service runs here: the super admin is told so instead of blocking every upload.
        ->and(fn () => $protection->set(Personas::user('super_admin'), true))->toThrow(AntivirusUnavailable::class);

    $this->actingAs(Personas::user('super_admin'))->get('/admin/system-status')->assertOk()
        ->assertSee(__('system_status.antivirus'))->assertSee(__('system_status.antivirus_enable'));
    $this->flushSession();
    $this->actingAs(Personas::user('security'))->get('/admin/system-status')->assertForbidden();
});

it('gives the right to read chats from outside to nobody in the demo world', function () {
    $this->actingAs(Personas::user('super_admin'))->get('/admin/chat-investigation')->assertForbidden();
    $this->flushSession();
    $this->actingAs(Personas::user('security'))->get('/admin/chat-investigation')->assertForbidden();

    expect(fn () => app(ChatReader::class)->investigate(Personas::user('security'), Chats::chat(Chats::HEADS), 'Verificare'))->toThrow(AuthorizationException::class)
        ->and(JournalEntry::query()->where('event_type', 'messaging.chat.investigated')->count())->toBe(0);
});
