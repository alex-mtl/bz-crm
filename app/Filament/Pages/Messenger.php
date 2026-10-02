<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\ChatSubjects;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatInvitation;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\People\Models\Person;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

/**
 * The messenger (ФО §6.6): the chats of the person on the left, the open chat as a tree of threads on the right.
 * The page shows and asks; what the person may read, write, pin or delete is decided by ChatAccess and the
 * actions of the Messaging module. It refreshes on a WebSocket event and, without one, by polling.
 */
class Messenger extends Page
{
    use ChecksPermissions;

    private const int THREADS = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'messenger';

    protected string $view = 'filament.pages.messenger';

    #[Url(as: 'chat')]
    public ?int $chatId = null;

    #[Url(as: 'message')]
    public ?int $messageId = null;

    public string $chatSearch = '';

    public string $search = '';

    public string $body = '';

    public ?int $replyTo = null;

    public ?int $quote = null;

    public int $threads = self::THREADS;

    public bool $showMembers = false;

    /** @var array<int, bool> roots of the threads the reader has folded */
    public array $collapsed = [];

    public static function canAccess(): bool
    {
        return static::allows('chats.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.messenger');
    }

    public static function getNavigationLabel(): string
    {
        return __('messaging.ui.messenger');
    }

    public static function getNavigationBadge(): ?string
    {
        $unread = app(ChatReader::class)->unreadTotal(static::actor());

        return $unread > 0 ? (string) $unread : null;
    }

    public function getTitle(): string
    {
        return __('messaging.ui.messenger');
    }

    public function mount(): void
    {
        app(SendMessages::class)->markDelivered(static::actor());
        if ($this->chat() !== null) {
            $this->body = (string) app(ChatAccess::class)->membership($this->chat(), static::actor()->person_id)?->draft;
        }
    }

    /**
     * The open chat — only if the reader may read it; otherwise the page behaves as if no chat were chosen.
     */
    private function chat(): ?Chat
    {
        if ($this->chatId === null) {
            return null;
        }
        $chat = Chat::query()->find($this->chatId);

        return $chat !== null && app(ChatAccess::class)->mayRead(static::actor(), $chat) ? $chat : null;
    }

    /**
     * @return array<string, array{name: string, symbol: string}>
     */
    public function getReactionTypesProperty(): array
    {
        return CatalogItem::query()->ofCatalog('reaction_types')->selectable()->get()
            ->mapWithKeys(fn (CatalogItem $item): array => [$item->code => ['name' => $item->name(), 'symbol' => (string) $item->property('symbol', '•')]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actor = static::actor();
        $reader = app(ChatReader::class);
        $access = app(ChatAccess::class);
        $chat = $this->chat();
        $data = [
            'actor' => $actor,
            'chats' => $reader->chats($actor, $this->chatSearch),
            'chat' => $chat,
            'found' => filled($this->search) ? $reader->search($actor, $this->search, $chat)->limit(30)->get() : null,
            'mayStartGroup' => static::allows('chats.group.create'),
            'reader' => $reader,
        ];
        if ($chat === null) {
            return $data;
        }

        // Opening a chat is reading it.
        app(SendMessages::class)->markRead($actor, $chat);
        $messages = $reader->messages($actor, $chat, $this->threads, $this->messageId);
        $replies = $messages->whereNotNull('root_id')->countBy('root_id');
        $originals = $messages->filter(fn (Message $m): bool => $m->isForward())
            ->mapWithKeys(fn (Message $m): array => [$m->id => $reader->seesOriginal($actor, $m)]);

        return [
            ...$data,
            'title' => $reader->title($actor, $chat),
            'subjectUrl' => $chat->isSubject() ? app(ChatSubjects::class)->url($chat) : null,
            'messages' => $messages,
            'replies' => $replies,
            'originals' => $originals,
            'decor' => $reader->decorations($actor, $messages),
            'pinned' => $reader->pinned($actor, $chat),
            'member' => $access->membership($chat, $actor->person_id),
            'members' => ChatMember::query()->with('person')->where('chat_id', $chat->id)->orderBy('id')->get(),
            'links' => $access->canManage($actor, $chat)
                ? ChatInvitation::query()->where('chat_id', $chat->id)->whereNull('revoked_at')->latest('id')->get() : collect(),
            'mayWrite' => $access->mayWrite($actor, $chat),
            'mayManage' => $access->canManage($actor, $chat),
            'mayModerate' => $access->canModerate($actor, $chat),
            'mayPin' => $access->mayPin($actor, $chat),
            'hasMore' => Message::query()->where('chat_id', $chat->id)->whereNull('parent_id')->where('status', Message::SENT)->count() > $this->threads,
            'replyMessage' => $this->replyTo !== null ? $messages->firstWhere('id', $this->replyTo) : null,
            'quoteMessage' => $this->quote !== null ? $messages->firstWhere('id', $this->quote) : null,
        ];
    }

    public function open(int $chatId): void
    {
        $this->saveDraft();
        $this->chatId = $chatId;
        $this->messageId = null;
        $this->replyTo = null;
        $this->quote = null;
        $this->search = '';
        $this->threads = self::THREADS;
        $this->showMembers = false;
        $chat = $this->chat();
        $this->body = $chat !== null ? (string) app(ChatAccess::class)->membership($chat, static::actor()->person_id)?->draft : '';
    }

    public function jump(int $chatId, int $messageId): void
    {
        $this->open($chatId);
        $this->messageId = $messageId;
    }

    public function more(): void
    {
        $this->threads += self::THREADS;
    }

    /**
     * The typed text survives leaving the field, the chat and the device.
     */
    public function updatedBody(): void
    {
        $this->saveDraft();
    }

    private function saveDraft(): void
    {
        $chat = $this->chat();
        if ($chat !== null && app(ChatAccess::class)->mayWrite(static::actor(), $chat)) {
            app(SendMessages::class)->saveDraft(static::actor(), $chat, $this->body);
        }
    }

    public function send(): void
    {
        $chat = $this->chat();
        if ($chat === null) {
            return;
        }
        if (static::attempt(fn () => app(SendMessages::class)->send(static::actor(), $chat, [
            'body' => $this->body, 'parent_id' => $this->replyTo, 'quoted_message_id' => $this->quote,
        ]))) {
            $this->body = '';
            $this->replyTo = null;
            $this->quote = null;
        }
    }

    public function reply(int $messageId): void
    {
        $this->replyTo = $messageId;
        $this->quote = null;
    }

    public function quoteMessage(int $messageId): void
    {
        $this->quote = $messageId;
        $this->replyTo = $messageId;
    }

    public function cancelReply(): void
    {
        $this->replyTo = null;
        $this->quote = null;
    }

    public function toggleThread(int $rootId): void
    {
        $this->collapsed[$rootId] = ! ($this->collapsed[$rootId] ?? false);
    }

    public function react(int $messageId, string $code): void
    {
        static::attempt(fn () => app(SendMessages::class)->react(static::actor(), $this->message($messageId), $code));
    }

    public function vote(int $messageId, int $optionId): void
    {
        static::attempt(fn () => app(SendMessages::class)->vote(static::actor(), $this->message($messageId), $optionId));
    }

    public function pin(int $messageId, bool $pinned): void
    {
        static::attempt(fn () => app(SendMessages::class)->pin(static::actor(), $this->message($messageId), $pinned));
    }

    public function deleteMessage(int $messageId): void
    {
        static::attempt(fn () => app(SendMessages::class)->delete(static::actor(), $this->message($messageId)));
    }

    public function setNotify(string $level): void
    {
        $chat = $this->chat();
        if ($chat !== null) {
            static::attempt(fn () => app(ManageChats::class)->setNotify(static::actor(), $chat, $level), __('admin.saved'));
        }
    }

    public function leave(): void
    {
        $chat = $this->chat();
        if ($chat !== null && static::attempt(fn () => app(ManageChats::class)->leave(static::actor(), $chat))) {
            $this->chatId = null;
        }
    }

    public function removeMember(int $personId): void
    {
        $chat = $this->chat();
        if ($chat !== null) {
            static::attempt(fn () => app(ManageChats::class)->removeMember(static::actor(), $chat, Person::query()->findOrFail($personId)));
        }
    }

    public function setRole(int $personId, string $role): void
    {
        $chat = $this->chat();
        if ($chat !== null) {
            static::attempt(fn () => app(ManageChats::class)->setRole(static::actor(), $chat, Person::query()->findOrFail($personId), $role));
        }
    }

    public function revokeLink(int $invitationId): void
    {
        static::attempt(fn () => app(ManageChats::class)->revokeLink(static::actor(), ChatInvitation::query()->where('chat_id', $this->chatId)->findOrFail($invitationId)));
    }

    /**
     * A message of the open chat — never of another one, whatever id the browser sends.
     */
    private function message(int $messageId): Message
    {
        return Message::query()->where('chat_id', $this->chat()?->id)->findOrFail($messageId);
    }

    public function dialogAction(): Action
    {
        return Action::make('dialog')
            ->modalHeading(__('messaging.ui.new_dialog'))
            ->schema([
                Select::make('person_id')->label(__('groups.ui.person'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, null, true))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
            ])
            ->action(function (array $data): void {
                static::attempt(function () use ($data): void {
                    $chat = app(ManageChats::class)->direct(static::actor(), Person::query()->findOrFail($data['person_id']));
                    $this->open($chat->id);
                });
            });
    }

    public function groupAction(): Action
    {
        return Action::make('group')
            ->modalHeading(__('messaging.ui.new_group'))
            ->schema([
                TextInput::make('title')->label(__('messaging.ui.chat_title'))->required()->maxLength(255),
                $this->peopleField('member_ids')->label(__('messaging.ui.members')),
            ])
            ->action(function (array $data): void {
                static::attempt(function () use ($data): void {
                    $chat = app(ManageChats::class)->createGroup(static::actor(), (string) $data['title'], array_map('intval', (array) ($data['member_ids'] ?? [])));
                    $this->open($chat->id);
                });
            });
    }

    /**
     * Everything a plain line of text cannot carry: files, a poll, mentions, a later moment of sending.
     */
    public function composeAction(): Action
    {
        return Action::make('compose')
            ->modalHeading(__('messaging.ui.compose'))
            ->modalSubmitActionLabel(__('messaging.ui.send'))
            ->fillForm(fn (): array => ['body' => $this->body])
            ->schema(function (): array {
                $chat = $this->chat();
                $others = $chat !== null
                    ? ChatMember::query()->with('person')->where('chat_id', $chat->id)->where('person_id', '!=', static::actor()->person_id)->get()
                        ->mapWithKeys(fn (ChatMember $member): array => [$member->person_id => $member->person->fullName()])->all()
                    : [];

                return [
                    Textarea::make('body')->label(__('messaging.ui.text'))->rows(4)->maxLength(10000),
                    FileUpload::make('files')->label(__('messaging.ui.attachments'))->multiple()
                        ->maxFiles((int) config('messaging.max_attachments'))->maxSize(51200)
                        ->disk('local')->directory('messaging/incoming')->storeFileNamesIn('file_names')
                        ->helperText(__('messaging.ui.attachments_hint')),
                    Select::make('mention_person_ids')->label(__('messaging.ui.mention'))->multiple()->options($others)->visible($others !== []),
                    Toggle::make('mention_all')->label(__('messaging.ui.mention_all'))
                        ->visible($chat !== null && ! $chat->isDirect() && app(ChatAccess::class)->mayMentionAll(static::actor(), $chat)),
                    TagsInput::make('poll_options')->label(__('messaging.ui.poll'))->helperText(__('messaging.ui.poll_hint')),
                    DateTimePicker::make('send_at')->label(__('messaging.ui.send_at'))->seconds(false)->helperText(__('messaging.ui.send_at_hint')),
                ];
            })
            ->action(function (array $data): void {
                $chat = $this->chat();
                if ($chat === null) {
                    return;
                }
                $paths = array_values((array) ($data['files'] ?? []));
                $names = (array) ($data['file_names'] ?? []);
                $files = array_map(fn (string $path): array => [
                    'source' => Storage::disk('local')->path($path), 'name' => (string) ($names[$path] ?? basename($path)),
                ], $paths);

                $sent = static::attempt(fn () => app(SendMessages::class)->send(static::actor(), $chat, [
                    'body' => $data['body'] ?? null,
                    'parent_id' => $this->replyTo,
                    'quoted_message_id' => $this->quote,
                    'mention_person_ids' => array_map('intval', (array) ($data['mention_person_ids'] ?? [])),
                    'mention_all' => (bool) ($data['mention_all'] ?? false),
                    'poll_options' => array_values((array) ($data['poll_options'] ?? [])),
                    'send_at' => filled($data['send_at'] ?? null) ? Carbon::parse($data['send_at']) : null,
                ], $files));
                Storage::disk('local')->delete($paths);
                if ($sent) {
                    $this->body = '';
                    $this->replyTo = null;
                    $this->quote = null;
                }
            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->modalHeading(__('messaging.ui.edit'))
            ->fillForm(fn (array $arguments): array => ['body' => $this->message((int) ($arguments['message'] ?? 0))->body])
            ->schema([Textarea::make('body')->label(__('messaging.ui.text'))->rows(4)->required()->maxLength(10000)])
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(SendMessages::class)->edit(static::actor(), $this->message((int) ($arguments['message'] ?? 0)), (string) $data['body']),
            ));
    }

    public function forwardAction(): Action
    {
        return Action::make('forward')
            ->modalHeading(__('messaging.ui.forward'))
            ->modalDescription(__('messaging.ui.forward_hint'))
            ->schema([
                Select::make('chat_id')->label(__('messaging.ui.forward_to'))->required()->searchable()
                    ->options(fn (): array => collect(app(ChatReader::class)->chats(static::actor()))
                        ->reject(fn (array $row): bool => $row['chat']->id === $this->chatId || $row['chat']->archived_at !== null)
                        ->mapWithKeys(fn (array $row): array => [$row['chat']->id => $row['title']])->all()),
                Textarea::make('comment')->label(__('messaging.ui.comment'))->rows(2)->maxLength(2000),
            ])
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(SendMessages::class)->forward(
                    static::actor(), $this->message((int) ($arguments['message'] ?? 0)), Chat::query()->findOrFail($data['chat_id']), $data['comment'] ?? null,
                ),
                __('messaging.ui.forwarded'),
            ));
    }

    /**
     * "Превратить обсуждение в поручение" (ФО §6.6.3): the task starts with the thread as its description.
     */
    public function taskAction(): Action
    {
        return Action::make('task')
            ->modalHeading(__('messaging.ui.create_task'))
            ->visible(fn (): bool => TaskResource::canCreate())
            ->fillForm(function (array $arguments): array {
                $message = $this->message((int) ($arguments['message'] ?? 0));

                return [
                    'title' => str($message->body ?? '')->limit(120)->toString(),
                    'description' => app(ChatReader::class)->threadDigest(static::actor(), $message),
                    'type_code' => 'assignment',
                ];
            })
            ->schema([
                TextInput::make('title')->label(__('admin.tasks.title'))->required()->maxLength(255),
                Select::make('type_code')->label(__('admin.tasks.type'))->options(fn (): array => Options::catalog('task_types'))->required(),
                Textarea::make('description')->label(__('admin.tasks.description'))->rows(6),
                DateTimePicker::make('due_at')->label(__('admin.tasks.due'))->seconds(false),
                Select::make('assignees')->label(__('admin.tasks.assignees'))->multiple()->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search): array => TaskResource::assignableSearch($search))
                    ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()
                        ->mapWithKeys(fn (Person $person): array => [$person->id => $person->fullName()])->all()),
            ])
            ->action(fn (array $arguments, array $data) => static::attempt(function () use ($arguments, $data): void {
                $message = $this->message((int) ($arguments['message'] ?? 0));
                $task = app(ManageTasks::class)->create(static::actor(), [
                    'title' => (string) $data['title'], 'type_code' => (string) $data['type_code'],
                    'description' => $data['description'] ?? null, 'due_at' => $data['due_at'] ?? null,
                ], array_map('intval', (array) $data['assignees']));
                app(SendMessages::class)->linkTask(static::actor(), $message, $task->id, $task->title);
            }, __('messaging.ui.task_created')));
    }

    public function addMembersAction(): Action
    {
        return Action::make('addMembers')
            ->modalHeading(__('messaging.ui.add_members'))
            ->schema([$this->peopleField('member_ids')->label(__('messaging.ui.members'))->required()])
            ->action(function (array $data): void {
                $chat = $this->chat();
                if ($chat !== null) {
                    static::attempt(fn () => app(ManageChats::class)->addMembers(static::actor(), $chat, array_map('intval', (array) $data['member_ids'])), __('admin.saved'));
                }
            });
    }

    public function renameAction(): Action
    {
        return Action::make('rename')
            ->modalHeading(__('messaging.ui.rename'))
            ->fillForm(fn (): array => ['title' => $this->chat()?->title])
            ->schema([TextInput::make('title')->label(__('messaging.ui.chat_title'))->required()->maxLength(255)])
            ->action(function (array $data): void {
                $chat = $this->chat();
                if ($chat !== null) {
                    static::attempt(fn () => app(ManageChats::class)->rename(static::actor(), $chat, (string) $data['title']), __('admin.saved'));
                }
            });
    }

    public function linkAction(): Action
    {
        return Action::make('link')
            ->modalHeading(__('messaging.ui.invite_link'))
            ->schema([
                DateTimePicker::make('expires_at')->label(__('groups.ui.link_expires'))->seconds(false)->default(fn (): Carbon => now()->addDays(7)),
                TextInput::make('max_uses')->label(__('groups.ui.link_max_uses'))->numeric()->integer()->minValue(1),
            ])
            ->action(function (array $data): void {
                $chat = $this->chat();
                if ($chat === null) {
                    return;
                }
                static::attempt(function () use ($chat, $data): void {
                    $link = app(ManageChats::class)->inviteByLink(static::actor(), $chat,
                        filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
                        filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null);
                    // The token is shown once: only its hash is stored.
                    Notification::make()->title(__('groups.ui.link_created'))->body(MessengerJoin::getUrl(['token' => $link['token']]))
                        ->success()->persistent()->send();
                });
            });
    }

    private function peopleField(string $name): Select
    {
        return Select::make($name)->multiple()->searchable()
            ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, null, true))
            ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()
                ->mapWithKeys(fn (Person $person): array => [$person->id => $person->fullName()])->all());
    }
}
