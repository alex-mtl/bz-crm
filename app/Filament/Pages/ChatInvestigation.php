<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\Message;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\PersonSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Reading somebody's chats without being in them (каталог §8, 🔒): only with the reserved right, only with a
 * reason, and every look — at the list of a person's chats and at each chat — goes to the journal.
 */
class ChatInvestigation extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'chat-investigation';

    protected string $view = 'filament.pages.chat-investigation';

    public ?int $personId = null;

    public string $reason = '';

    /** @var list<array{id: int, title: string, type: string}> */
    public array $chats = [];

    public ?int $openChatId = null;

    public static function canAccess(): bool
    {
        return static::allows('chats.read.investigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.messenger');
    }

    public static function getNavigationLabel(): string
    {
        return __('messaging.ui.investigation');
    }

    public function getTitle(): string
    {
        return __('messaging.ui.investigation');
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label(__('messaging.ui.investigation_start'))
            ->modalDescription(__('messaging.ui.investigation_hint'))
            ->schema([
                Select::make('person_id')->label(__('groups.ui.person'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                Textarea::make('reason')->label(__('social.ui.reason'))->rows(2)->required()->maxLength(500),
            ])
            ->action(function (array $data): void {
                static::attempt(function () use ($data): void {
                    $rows = app(ChatReader::class)->investigationChats(static::actor(), Person::query()->findOrFail($data['person_id']), (string) $data['reason']);
                    $this->personId = (int) $data['person_id'];
                    $this->reason = (string) $data['reason'];
                    $this->openChatId = null;
                    $this->chats = array_map(fn (array $row): array => ['id' => $row['chat']->id, 'title' => $row['title'], 'type' => $row['chat']->type], $rows);
                });
            });
    }

    public function read(int $chatId): void
    {
        // Only a chat from the list just shown — not any id the browser may send.
        if (in_array($chatId, array_column($this->chats, 'id'), true)) {
            $this->openChatId = $chatId;
        }
    }

    /**
     * @return Collection<int, Message>
     */
    public function getTranscriptProperty(): Collection
    {
        if ($this->openChatId === null) {
            return collect();
        }
        $messages = collect();
        static::attempt(function () use (&$messages): void {
            $messages = app(ChatReader::class)->investigate(static::actor(), Chat::query()->findOrFail($this->openChatId), $this->reason);
        });

        return $messages;
    }
}
