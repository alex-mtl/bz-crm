<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Reading someone's sign-in history is itself a journal read (ADR-006).
        if ($this->viewerMay('users.login_history.read')) {
            app(EventJournal::class)->record('audit.viewed', $this->getRecord(), [], ['scope' => 'login_history']);
        }
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make(UserResource::securityActions())->button()->label(__('admin.users.actions'))];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.users.account'))->columns(2)->schema([
                TextEntry::make('person_name')->label(__('admin.fields.name'))
                    ->state(fn (User $record): string => $record->person->fullName()),
                TextEntry::make('email')->label(__('identity.fields.email'))->placeholder('—'),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => $state->label()),
                TextEntry::make('locale')->label(__('identity.fields.locale'))
                    ->formatStateUsing(fn (string $state): string => __('identity.locales.'.$state)),
                TextEntry::make('roles')->label(__('admin.fields.roles'))->badge()->placeholder('—')
                    ->state(fn (User $record): array => array_values(UserResource::roleNamesOf($record))),
                TextEntry::make('providers')->label(__('identity.profile.connected_providers'))->badge()->placeholder('—')
                    ->state(fn (User $record): array => SocialIdentity::query()->where('user_id', $record->id)->pluck('provider')->all()),
                TextEntry::make('two_factor')->label(__('admin.users.two_factor'))
                    ->state(fn (User $record): string => $record->hasAppAuthentication() ? __('admin.yes') : __('admin.no')),
                TextEntry::make('last_login_at')->label(__('admin.users.last_login'))->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
            ]),
            Section::make(__('admin.users.login_history'))
                ->visible(fn (): bool => $this->viewerMay('users.login_history.read'))
                ->schema([
                    RepeatableEntry::make('login_history')->hiddenLabel()->placeholder(__('admin.users.no_history'))
                        ->state(fn (User $record): array => JournalEntry::query()
                            ->where('subject_type', $record->getMorphClass())->where('subject_id', (string) $record->id)
                            ->where('event_type', 'like', 'auth.%')
                            ->latest('id')->limit(20)->get()
                            ->map(fn (JournalEntry $entry): array => [
                                'at' => $entry->occurred_at,
                                'event' => __('journal.events.'.$entry->event_type),
                                'ip' => $entry->ip_address,
                                'agent' => $entry->user_agent,
                            ])->all())
                        ->columns(4)
                        ->schema([
                            TextEntry::make('at')->hiddenLabel()->dateTime(),
                            TextEntry::make('event')->hiddenLabel(),
                            TextEntry::make('ip')->hiddenLabel()->placeholder('—'),
                            TextEntry::make('agent')->hiddenLabel()->placeholder('—')->limit(60),
                        ]),
                ]),
        ]);
    }

    private function viewerMay(string $code): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, $code);
    }
}
