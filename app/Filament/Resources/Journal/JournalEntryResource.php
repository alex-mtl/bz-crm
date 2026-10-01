<?php

declare(strict_types=1);

namespace App\Filament\Resources\Journal;

use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Journal\Pages\ListJournalEntries;
use App\Filament\Resources\Journal\Pages\ViewJournalEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Event journal viewer (ADR-006): read-only, by audit.read. Viewing and exporting are journaled too.
 */
class JournalEntryResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = JournalEntry::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'journal';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.system');
    }

    public static function getModelLabel(): string
    {
        return __('admin.journal.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('journal.title');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'audit.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('audit.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('audit.read', $record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function actorName(JournalEntry $entry): string
    {
        if ($entry->actor_user_id !== null) {
            $user = User::query()->with('person')->find($entry->actor_user_id);

            return $user !== null ? $user->person->fullName() : '#'.$entry->actor_user_id;
        }

        return __('journal.actor_types.'.$entry->actor_type->value);
    }

    /**
     * "Impersonation — on behalf of X" (Д-19), "by delegation"; null for own rights.
     */
    public static function actingAsLabel(JournalEntry $entry): ?string
    {
        if ($entry->acting_as === ActingAs::Own) {
            return null;
        }
        $label = __('journal.acting_as.'.$entry->acting_as->value);
        $onBehalfOf = $entry->context['on_behalf_of_user_id'] ?? null;
        if ($entry->acting_as === ActingAs::Impersonation && $onBehalfOf !== null) {
            $user = User::query()->with('person')->find($onBehalfOf);
            $label .= ': '.__('journal.on_behalf_of', ['name' => $user !== null ? $user->person->fullName() : '#'.$onBehalfOf]);
        }

        return $label;
    }

    public static function table(Table $table): Table
    {
        /** @param class-string<BackedEnum> $enum */
        $enumOptions = function (string $enum, string $prefix): array {
            $options = [];
            foreach ($enum::cases() as $case) {
                $options[$case->value] = __($prefix.$case->value);
            }

            return $options;
        };

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label(__('admin.journal.occurred_at'))->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('event_type')->label(__('admin.journal.event'))
                    ->formatStateUsing(fn (string $state): string => __('journal.events.'.$state))
                    ->description(fn (JournalEntry $record): string => $record->event_type)
                    ->wrap(),
                TextColumn::make('category')->label(__('admin.journal.category'))->badge()
                    ->formatStateUsing(fn (EventCategory $state): string => __('journal.categories.'.$state->value)),
                TextColumn::make('severity')->label(__('admin.journal.severity'))->badge()
                    ->formatStateUsing(fn (EventSeverity $state): string => __('journal.severities.'.$state->value))
                    ->color(fn (EventSeverity $state): string => match ($state) {
                        EventSeverity::Critical => 'danger', EventSeverity::Warning => 'warning', EventSeverity::Notice => 'info', default => 'gray',
                    }),
                TextColumn::make('actor')->label(__('admin.journal.actor'))
                    ->state(fn (JournalEntry $record): string => static::actorName($record))
                    ->description(fn (JournalEntry $record): ?string => static::actingAsLabel($record)),
                TextColumn::make('subject')->label(__('admin.journal.subject'))
                    ->state(fn (JournalEntry $record): ?string => $record->subject_type !== null ? class_basename($record->subject_type).' #'.$record->subject_id : null)
                    ->placeholder('—'),
                TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('correlation_id')->label(__('admin.journal.correlation'))->fontFamily('mono')->limit(8)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type')->label(__('admin.journal.event'))->multiple()->searchable()
                    ->options(fn (): array => collect(app(EventTypeRegistry::class)->all())
                        ->mapWithKeys(fn (EventType $type): array => [$type->code => $type->label().' · '.$type->code])->sort()->all()),
                SelectFilter::make('category')->label(__('admin.journal.category'))->multiple()
                    ->options($enumOptions(EventCategory::class, 'journal.categories.')),
                SelectFilter::make('severity')->label(__('admin.journal.severity'))->multiple()
                    ->options($enumOptions(EventSeverity::class, 'journal.severities.')),
                SelectFilter::make('actor_type')->label(__('admin.journal.actor_type'))
                    ->options($enumOptions(ActorType::class, 'journal.actor_types.')),
                SelectFilter::make('actor_user_id')->label(__('admin.journal.actor'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()->with('person')
                        ->whereHas('person', fn ($query) => $query->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                        ->orWhere('email', 'like', "%{$search}%")->limit(20)->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => $u->person->fullName()])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => User::query()->with('person')->find($value)?->person->fullName()),
                Filter::make('subject')->label(__('admin.journal.subject'))
                    ->schema([
                        TextInput::make('subject_type')->label(__('admin.journal.subject_type')),
                        TextInput::make('subject_id')->label(__('admin.journal.subject_id')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['subject_type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', 'like', "%{$type}%"))
                        ->when($data['subject_id'] ?? null, fn (Builder $query, string $id) => $query->where('subject_id', $id))),
                Filter::make('period')->label(__('admin.journal.period'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.journal.from')),
                        DatePicker::make('until')->label(__('admin.journal.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<', now()->parse($date)->addDay()))),
                Filter::make('correlation')->label(__('admin.journal.correlation'))
                    ->schema([TextInput::make('correlation_id')->label(__('admin.journal.correlation'))])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['correlation_id'] ?? null, fn (Builder $query, string $id) => $query->where('correlation_id', $id))),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('sameCorrelation')
                    ->label(__('admin.journal.same_correlation'))
                    ->icon(Heroicon::OutlinedLink)
                    ->url(fn (JournalEntry $record): string => static::getUrl('index', [
                        'filters' => ['correlation' => ['correlation_id' => $record->correlation_id]],
                    ])),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('occurred_at')->label(__('admin.journal.occurred_at'))->dateTime('Y-m-d H:i:s'),
                TextEntry::make('event_type')->label(__('admin.journal.event'))
                    ->formatStateUsing(fn (string $state): string => __('journal.events.'.$state).' ('.$state.')'),
                TextEntry::make('category')->label(__('admin.journal.category'))
                    ->formatStateUsing(fn (EventCategory $state): string => __('journal.categories.'.$state->value)),
                TextEntry::make('actor')->label(__('admin.journal.actor'))->state(fn (JournalEntry $record): string => static::actorName($record)),
                TextEntry::make('acting_as')->label(__('admin.journal.acting_as'))
                    ->state(fn (JournalEntry $record): string => static::actingAsLabel($record) ?? __('journal.acting_as.own')),
                TextEntry::make('subject')->label(__('admin.journal.subject'))->placeholder('—')
                    ->state(fn (JournalEntry $record): ?string => $record->subject_type !== null ? $record->subject_type.' #'.$record->subject_id : null),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('user_agent')->label(__('admin.journal.device'))->placeholder('—')->columnSpan(2),
                TextEntry::make('request_id')->label('request_id')->placeholder('—')->fontFamily('mono'),
                TextEntry::make('correlation_id')->label('correlation_id')->fontFamily('mono')->columnSpan(2),
            ]),
            Section::make(__('admin.journal.changes'))->columns(2)->schema([
                KeyValueEntry::make('old_values')->label(__('admin.journal.old_values'))
                    ->state(fn (JournalEntry $record): array => static::flatten($record->old_values ?? [])),
                KeyValueEntry::make('new_values')->label(__('admin.journal.new_values'))
                    ->state(fn (JournalEntry $record): array => static::flatten($record->new_values ?? [])),
                KeyValueEntry::make('context')->label(__('admin.journal.context'))->columnSpanFull()
                    ->state(fn (JournalEntry $record): array => static::flatten($record->context ?? [])),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    protected static function flatten(array $values): array
    {
        return collect($values)->map(fn (mixed $v): string => is_scalar($v) || $v === null
            ? var_export($v, true)
            : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'view' => ViewJournalEntry::route('/{record}'),
        ];
    }
}
