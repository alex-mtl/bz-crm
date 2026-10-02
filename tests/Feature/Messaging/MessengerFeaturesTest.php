<?php

use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Files\AntivirusProtection;
use App\Domain\Files\AttachmentScanner;
use App\Domain\Files\Exceptions\FileRejected;
use App\Domain\Files\ScanVerdict;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\GroupAccess;
use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\Discussions;
use App\Domain\Messaging\Events\ChatUpdated;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageAttachment;
use App\Domain\Messaging\Retention;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Models\Post;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\TaskWorkflow;
use App\Infrastructure\Antivirus\ClamAvScanner;
use App\Support\Settings\SystemSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SocialFixture;

/*
 * ФО §6.6.2–6.6.4, ТЗ §20–21 — forwarding that respects the source chat, search inside one's own chats,
 * reactions, polls, pins, attachments behind the antivirus, discussions of objects, retention, real-time.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->chats = app(ManageChats::class);
    $this->messages = app(SendMessages::class);
    $this->reader = app(ChatReader::class);
    $this->access = app(ChatAccess::class);
    $this->say = fn ($user, Chat $chat, string $body, array $data = []): Message => $this->messages->send($user, $chat, ['body' => $body, ...$data]);
    // The protection is turned on (Д-28) with a scanner that answers what the test needs.
    $this->scanner = function (ScanVerdict $verdict): void {
        app(SystemSettings::class)->put(AntivirusProtection::SETTING, true);
        app()->bind(AttachmentScanner::class, fn (): AttachmentScanner => new class($verdict) implements AttachmentScanner
        {
            public function __construct(private readonly ScanVerdict $verdict) {}

            public function scan(string $absolutePath): ScanVerdict
            {
                return $this->verdict;
            }

            public function reachable(): bool
            {
                return $this->verdict !== ScanVerdict::Unavailable;
            }
        });
    };
    $this->file = function (string $content = 'conținut'): array {
        $source = tempnam(sys_get_temp_dir(), 'msg');
        file_put_contents($source, $content);

        return ['source' => $source, 'name' => 'Plan.PDF', 'mime' => 'application/pdf'];
    };
});

it('forwards a message without opening the chat it came from', function () {
    $o = $this->org;
    $closed = $this->chats->createGroup($o->a1, 'Conducerea filialei', [$o->headA->person_id]);
    $wide = $this->chats->createGroup($o->a1, 'Voluntari', [$o->a2->person_id, $o->headA->person_id]);
    $secret = ($this->say)($o->headA, $closed, 'Bugetul pe trimestru: 40 000 lei');

    $forwarded = $this->messages->forward($o->a1, $secret, $wide, 'De discutat luni');

    expect($forwarded)->body->toBe('De discutat luni')->forwarded_from_message_id->toBe($secret->id)
        // In the target chat: who was in the source chat sees the original, who was not sees only the fact.
        ->and($this->reader->seesOriginal($o->headA, $forwarded))->toBeTrue()
        ->and($this->reader->seesOriginal($o->a2, $forwarded))->toBeFalse()
        // The text is not copied, so the search cannot find it for an outsider either.
        ->and($this->reader->search($o->a2, 'Bugetul')->count())->toBe(0)
        ->and($this->reader->search($o->headA, 'Bugetul')->pluck('id')->all())->toBe([$secret->id])
        // An outsider cannot pass on what they cannot see; nor can anyone forward out of a chat they are not in.
        ->and(fn () => $this->messages->forward($o->a2, $forwarded, $this->chats->direct($o->a2, $o->b1->person)))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->forward($o->a2, $secret, $wide))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->forward($o->a1, $secret, $this->chats->direct($o->a2, $o->b1->person)))->toThrow(AuthorizationException::class);

    // A forward of a forward still points at the first original.
    $again = $this->messages->forward($o->headA, $forwarded, $this->chats->direct($o->headA, $o->regionHead->person));
    expect($again->forwarded_from_message_id)->toBe($secret->id)
        ->and($this->reader->seesOriginal($o->regionHead, $again))->toBeFalse();

    // The original is deleted: nobody sees it through the forwards any more.
    $this->messages->delete($o->headA, $secret);
    expect($this->reader->seesOriginal($o->headA, $forwarded->fresh()))->toBeFalse();
});

it('searches only inside the chats the reader is part of', function () {
    $o = $this->org;
    $mine = $this->chats->createGroup($o->a1, 'Sector Centru', [$o->a2->person_id]);
    $theirs = $this->chats->createGroup($o->b1, 'Sector Botanica', [$o->headB->person_id]);
    $inMine = ($this->say)($o->a1, $mine, 'Adunarea are loc sâmbătă');
    ($this->say)($o->b1, $theirs, 'Adunarea are loc duminică');
    $direct = ($this->say)($o->a2, $this->chats->direct($o->a2, $o->a1->person), 'Vii la adunarea de sâmbătă?');

    expect($this->reader->search($o->a2, 'adunarea')->pluck('id')->all())->toBe([$direct->id, $inMine->id])
        ->and($this->reader->search($o->a2, 'duminică')->count())->toBe(0)
        ->and($this->reader->search($o->a2, 'adunarea', $mine)->pluck('id')->all())->toBe([$inMine->id])
        ->and($this->reader->search($o->a2, 'adunarea', $theirs)->count())->toBe(0)
        ->and($this->reader->search($o->balti1, 'adunarea')->count())->toBe(0)
        ->and($this->reader->search($o->a2, '%')->count())->toBe(0)
        ->and($this->reader->search($o->a2, ' ')->count())->toBe(0);
});

it('takes reactions, votes and pins', function () {
    $o = $this->org;
    $chat = $this->chats->createGroup($o->a1, 'Sector Centru', [$o->a2->person_id, $o->headA->person_id]);
    $this->chats->setRole($o->a1, $chat, $o->headA->person, ChatMember::MODERATOR);
    $this->access->forget();
    $message = ($this->say)($o->a2, $chat, 'Am terminat lista');
    $poll = ($this->say)($o->a1, $chat, 'Când ne întâlnim?', ['poll_options' => ['Sâmbătă', ' Duminică ', '']]);
    [$saturday, $sunday] = $poll->pollOptions()->get()->all();

    $this->messages->react($o->a1, $message, 'thanks');
    $this->messages->react($o->headA, $message, 'like');
    $this->messages->react($o->headA, $message, 'like');     // the same again removes it
    $this->messages->vote($o->a2, $poll, $saturday->id);
    $this->messages->vote($o->a2, $poll, $sunday->id);
    $this->messages->vote($o->headA, $poll, $sunday->id);
    $this->messages->pin($o->headA, $message);

    $decorations = $this->reader->decorations($o->a1, collect([$message, $poll]));

    expect($poll->kind)->toBe(Message::POLL)
        ->and($decorations['reactions'][$message->id])->toBe(['thanks' => 1])
        ->and($decorations['mine'][$message->id])->toBe('thanks')
        ->and($decorations['votes'])->toBe([$sunday->id => 2])
        ->and($this->reader->pinned($o->a2, $chat)->pluck('id')->all())->toBe([$message->id])
        ->and($this->reader->pinned($o->balti1, $chat))->toBeEmpty()
        ->and(fn () => $this->messages->pin($o->a2, $message))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->react($o->balti1, $message, 'like'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->react($o->a1, $message, 'hate'))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => $this->messages->vote($o->a2, $poll, 999999))->toThrow(MessagingRuleViolation::class)
        ->and(fn () => ($this->say)($o->a1, $chat, 'Întrebare', ['poll_options' => ['Una']]))->toThrow(MessagingRuleViolation::class);

    // In a dialog either of the two pins.
    $direct = $this->chats->direct($o->a1, $o->b1->person);
    $this->messages->pin($o->b1, ($this->say)($o->a1, $direct, 'Adresa: str. Columna 12'));
    expect($this->reader->pinned($o->a1, $direct))->toHaveCount(1);
});

it('opens an attachment only to the members of the chat, and only after the antivirus let it through', function () {
    Storage::fake('local');
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);

    // No scanner configured: the file is accepted and marked as unchecked.
    $message = $this->messages->send($o->a1, $chat, [], [($this->file)()]);
    $attachment = $message->attachments()->sole();

    expect($attachment)->scan_status->toBe(MessageAttachment::SKIPPED)->kind->toBe('file')->original_name->toBe('Plan.PDF')
        ->and($attachment->path)->toStartWith('messaging/chats/'.$chat->id.'/')->toEndWith('.pdf')
        ->and($this->messages->attachmentPath($o->b1, $attachment))->toEndWith('.pdf')
        ->and(fn () => $this->messages->attachmentPath($o->a2, $attachment))->toThrow(AuthorizationException::class);

    ($this->scanner)(ScanVerdict::Clean);
    $clean = $this->messages->send($o->a1, $chat, ['body' => 'Verificat'], [($this->file)()])->attachments()->sole();
    expect($clean->scan_status)->toBe(MessageAttachment::CLEAN)->and($clean->scanned_at)->not->toBeNull();

    // Infected: the file leaves the disk, the mark stays, the journal knows.
    ($this->scanner)(ScanVerdict::Infected);
    $infected = $this->messages->send($o->a1, $chat, ['body' => 'Atenție'], [($this->file)()])->attachments()->sole();

    expect($infected->scan_status)->toBe(MessageAttachment::INFECTED)
        ->and(Storage::disk('local')->exists($infected->path))->toBeFalse()
        ->and(fn () => $this->messages->attachmentPath($o->b1, $infected))->toThrow(AuthorizationException::class)
        ->and(journalCount('messaging.attachment.infected'))->toBe(1);

    // The scanner does not answer: the file waits and cannot be opened meanwhile.
    ($this->scanner)(ScanVerdict::Unavailable);
    $waiting = $this->messages->send($o->a1, $chat, ['body' => 'În așteptare'], [($this->file)()])->attachments()->sole();

    expect($waiting->scan_status)->toBe(MessageAttachment::PENDING)
        ->and(fn () => $this->messages->attachmentPath($o->b1, $waiting))->toThrow(AuthorizationException::class)
        ->and((new ClamAvScanner('127.0.0.1', 1, 0.3))->scan(($this->file)()['source']))->toBe(ScanVerdict::Unavailable);
});

it('refuses an infected or unchecked file in posts and events too', function () {
    Storage::fake('local');
    $o = $this->org;
    $event = app(ManageEvents::class)->create($o->a1, ['title' => 'Întâlnire', 'type_code' => 'meeting', 'starts_at' => now()->subHour()]);

    ($this->scanner)(ScanVerdict::Infected);
    expect(fn () => app(ManagePosts::class)->create($o->a1, ['visibility' => Post::PRIVATE], [($this->file)()]))->toThrow(FileRejected::class)
        ->and(fn () => app(ManageEvents::class)->publishResults($o->a1, $event, 'Rezultate', [($this->file)()]))->toThrow(FileRejected::class)
        ->and(Post::query()->count())->toBe(0);

    ($this->scanner)(ScanVerdict::Unavailable);
    expect(fn () => app(ManagePosts::class)->create($o->a1, ['visibility' => Post::PRIVATE], [($this->file)()]))->toThrow(FileRejected::class);

    ($this->scanner)(ScanVerdict::Clean);
    expect(app(ManagePosts::class)->create($o->a1, ['visibility' => Post::PRIVATE], [($this->file)()])->attachments()->count())->toBe(1);
});

it('shows the discussion of an object to those who may read the object, and to nobody else', function () {
    TaskWorkflow::ensureDefaults();
    $o = $this->org;
    $tasks = app(ManageTasks::class);
    $task = $tasks->create($o->headA, ['title' => 'Pregătirea sălii', 'type_code' => 'assignment'], [$o->a1->person_id]);
    $tasks->post($o->headA, $task, 'Sala e rezervată de la 17:00.');
    $chat = app(Discussions::class)->findFor($task);
    $this->access->forget();

    expect($chat->isSubject())->toBeTrue()
        ->and($this->reader->title($o->a1, $chat))->toBe('Pregătirea sălii')
        ->and(collect($this->reader->chats($o->a1))->pluck('chat.id')->all())->toBe([$chat->id])
        ->and($this->reader->unreadCounts($o->a1))->toBe([$chat->id => 1])
        // The messenger and the page of the task show the same chat: a reply inside a thread lands in both.
        ->and(($this->say)($o->a1, $chat, 'Aduc proiectorul.', ['parent_id' => $chat->messages()->sole()->id])->depth)->toBe(1)
        ->and($chat->messages()->count())->toBe(2)
        // Not an assignee, not the head of the branch: no access to the task — no access to its discussion.
        ->and($this->access->mayRead($o->b1, $chat))->toBeFalse()
        ->and(fn () => ($this->say)($o->b1, $chat, 'X'))->toThrow(AuthorizationException::class)
        ->and($this->reader->search($o->b1, 'proiectorul')->count())->toBe(0);

    // A stale membership row opens nothing: access follows the object, not the row.
    ChatMember::query()->create(['chat_id' => $chat->id, 'person_id' => $o->b1->person_id, 'joined_at' => now()]);
    $this->access->forget();
    expect($this->reader->chats($o->b1))->toBe([])
        ->and($this->reader->search($o->b1, 'proiectorul')->count())->toBe(0);

    // The chat of a group: for its members, gone for whoever leaves the group.
    $group = $this->social->group($o->a1, 'Voluntari', 'open', [$o->a2]);
    app(ManageGroups::class)->post($o->a1, $group, 'Salut, echipă');
    $groupChat = app(Discussions::class)->findFor($group);
    $this->access->forget();
    expect($this->access->mayRead($o->a2, $groupChat))->toBeTrue()
        ->and($this->reader->title($o->a2, $groupChat))->toBe('Voluntari');

    app(ManageGroups::class)->leave($o->a2, $group);
    $this->access->forget();
    app(GroupAccess::class)->forget();
    expect($this->access->mayRead($o->a2, $groupChat))->toBeFalse();
});

it('turns a thread into a task and remembers it', function () {
    TaskWorkflow::ensureDefaults();
    $o = $this->org;
    $chat = $this->chats->createGroup($o->headA, 'Filiala A', [$o->a1->person_id, $o->a2->person_id]);
    $root = ($this->say)($o->a1, $chat, 'Nu avem pliante pentru sâmbătă.');
    $reply = ($this->say)($o->a2, $chat, 'Tipografia poate livra vineri.', ['parent_id' => $root->id]);

    // The Tasks module creates the task; the messenger links it to the thread.
    $task = app(ManageTasks::class)->create($o->headA, [
        'title' => 'Comandă pliante', 'type_code' => 'assignment', 'description' => $this->reader->threadDigest($o->headA, $reply),
    ], [$o->a2->person_id]);
    $note = $this->messages->linkTask($o->headA, $reply, $task->id, $task->title);

    expect($task->description)->toContain('Nu avem pliante')->toContain('Tipografia poate livra vineri.')
        ->and($root->fresh()->task_id)->toBe($task->id)
        ->and($note)->kind->toBe(Message::SYSTEM)->parent_id->toBe($root->id)
        ->and($note->body)->toContain('Comandă pliante')
        ->and(fn () => $this->messages->linkTask($o->b1, $reply, $task->id, $task->title))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->messages->edit($o->headA, $note, 'Altceva'))->toThrow(AuthorizationException::class);
});

it('keeps messages indefinitely unless the administrator sets a term', function () {
    Storage::fake('local');
    $o = $this->org;
    $retention = app(Retention::class);
    $chat = $this->chats->direct($o->a1, $o->b1->person);
    $old = $this->messages->send($o->a1, $chat, ['body' => 'Mesaj vechi'], [($this->file)()]);
    $path = $old->attachments()->sole()->path;
    $this->travel(7)->months();
    $recent = ($this->say)($o->b1, $chat, 'Mesaj recent');

    expect($retention->months())->toBeNull()
        ->and($retention->purge())->toBe(0)
        ->and(fn () => $retention->set($o->orgHead, 6))->toThrow(AuthorizationException::class)
        ->and(fn () => $retention->set($o->admin, 0))->toThrow(MessagingRuleViolation::class);

    $retention->set($o->admin, 6);

    expect($retention->months())->toBe(6)
        ->and($retention->purge())->toBe(1)
        ->and(Message::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(journalCount('messaging.retention.changed'))->toBe(1)
        ->and(journalCount('messaging.retention.purged'))->toBe(1);

    $retention->set($o->admin, null);
    $this->artisan('messaging:purge')->expectsOutputToContain('No retention term is set.')->assertSuccessful();
});

it('reads a chat from outside only for an investigation, by a right nobody holds by default', function () {
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);
    ($this->say)($o->a1, $chat, 'Între noi');

    expect(fn () => $this->reader->investigate($o->admin, $chat, 'Plângere'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->reader->investigate($o->orgHead, $chat, 'Plângere'))->toThrow(AuthorizationException::class)
        ->and(journalCount('messaging.chat.investigated'))->toBe(0);
});

it('broadcasts only the fact of a change, to channels only the members may join', function () {
    Event::fake([ChatUpdated::class]);
    $o = $this->org;
    $chat = $this->chats->direct($o->a1, $o->b1->person);
    $message = ($this->say)($o->a1, $chat, 'Text confidențial');

    Event::assertDispatched(ChatUpdated::class, function (ChatUpdated $event) use ($chat, $message, $o): bool {
        $channels = array_map(fn ($channel): string => $channel->name, $event->broadcastOn());

        return $event->chatId === $chat->id && $event->messageId === $message->id
            && in_array('private-chat.'.$chat->id, $channels, true) && in_array('private-messenger.'.$o->b1->id, $channels, true)
            && ! str_contains((string) json_encode($event->broadcastWith()), 'confidențial');
    });

    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]]);
    require base_path('routes/channels.php');
    $auth = fn ($user, string $channel) => $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $auth($o->b1, 'private-chat.'.$chat->id)->assertOk();
    $auth($o->b1, 'private-messenger.'.$o->b1->id)->assertOk();
    $this->flushSession();
    $auth($o->a2, 'private-chat.'.$chat->id)->assertForbidden();
    $auth($o->a2, 'private-messenger.'.$o->b1->id)->assertForbidden();
});
