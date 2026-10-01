<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals;

use App\Domain\CRM\Models\Appeal;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Appeals\Pages\CreateAppeal;
use App\Filament\Resources\Appeals\Pages\ListAppeals;
use App\Filament\Resources\Appeals\Pages\ViewAppeal;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Appeals (ФО §6.9.3): the list shows what appeals.read allows — the scope, the responsible, the applicant's own.
 */
class AppealResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Appeal::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.appeals.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.appeals.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'appeals.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('appeals.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('appeals.read', $record);
    }

    public static function canCreate(): bool
    {
        return static::allows('appeals.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            Appeal::DONE => 'success', Appeal::REJECTED => 'danger', Appeal::IN_PROGRESS => 'primary', default => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return collect(Appeal::STATUSES)->mapWithKeys(fn (string $status): array => [$status => __('crm.appeal_statuses.'.$status)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')->label(__('admin.appeals.title'))->required()->maxLength(255)->columnSpanFull(),
                Select::make('type_code')->label(__('admin.appeals.type'))->options(fn (): array => Options::catalog('appeal_types'))->required(),
                Select::make('source_code')->label(__('admin.people.source'))->options(fn (): array => Options::catalog('contact_sources')),
                Select::make('priority_code')->label(__('admin.appeals.priority'))->options(fn (): array => Options::catalog('appeal_priorities'))
                    ->default('normal')->required()->helperText(__('admin.appeals.priority_hint')),
                Textarea::make('body')->label(__('admin.appeals.body'))->rows(5)->columnSpanFull(),
                Select::make('person_id')->label(__('admin.appeals.person'))->searchable()->helperText(__('admin.appeals.person_hint'))
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                Select::make('responsible_person_id')->label(__('admin.appeals.responsible'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                DateTimePicker::make('due_at')->label(__('admin.appeals.due'))->seconds(false)->helperText(__('admin.appeals.due_hint')),
            ]),
            Section::make(__('admin.tasks.placement'))->columns(2)->collapsed()->schema([
                Places::unit()->helperText(__('admin.appeals.unit_hint')),
                Places::territory(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $types = Options::catalog('appeal_types');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'responsible']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.appeals.number'))->searchable()->weight('bold'),
                TextColumn::make('title')->label(__('admin.appeals.title'))->wrap()
                    // Found by its subject and by the name of the applicant.
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $where) => $where
                        ->where('title', 'like', "%{$search}%")
                        ->orWhereHas('person', fn (Builder $person) => $person->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))
                    ->description(fn (Appeal $record): ?string => $record->person?->fullName()),
                TextColumn::make('type_code')->label(__('admin.appeals.type'))->formatStateUsing(fn (string $state): string => $types[$state] ?? $state)->toggleable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('crm.appeal_statuses.'.$state))
                    ->color(fn (string $state): string => static::statusColor($state)),
                TextColumn::make('priority_code')->label(__('admin.appeals.priority'))->badge()
                    ->formatStateUsing(fn (string $state): string => Options::catalog('appeal_priorities')[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger', 'high' => 'warning', default => 'gray',
                    })
                    ->description(fn (Appeal $record): ?string => $record->isFirstResponseOverdue() ? __('admin.appeals.first_response_overdue') : null),
                TextColumn::make('responsible_person_id')->label(__('admin.appeals.responsible'))->placeholder('—')
                    ->formatStateUsing(fn (Appeal $record): ?string => $record->responsible?->fullName()),
                TextColumn::make('due_at')->label(__('admin.appeals.due'))->dateTime('d.m.Y H:i')->sortable()->placeholder('—')
                    ->color(fn (Appeal $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (Appeal $record): ?string => $record->isOverdue() ? __('admin.tasks.overdue') : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->multiple()->options(static::statuses()),
                SelectFilter::make('type_code')->label(__('admin.appeals.type'))->options($types),
                SelectFilter::make('priority_code')->label(__('admin.appeals.priority'))->options(fn (): array => Options::catalog('appeal_priorities')),
                SelectFilter::make('unanswered')->label(__('admin.appeals.first_response_overdue'))->options(['1' => __('admin.yes')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $w) => $w
                        ->where('status', Appeal::NEW)->whereNotNull('first_response_due_at')->where('first_response_due_at', '<', now()))),
                SelectFilter::make('mine')->label(__('admin.appeals.mine'))->options(['1' => __('admin.yes')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $w) => $w->where('responsible_person_id', static::actor()->person_id))),
                TernaryFilter::make('overdue')->label(__('admin.tasks.overdue'))
                    ->queries(true: fn (Builder $query) => $query->whereIn('status', [Appeal::NEW, Appeal::IN_PROGRESS])->whereNotNull('due_at')->where('due_at', '<', now()), false: fn (Builder $query) => $query->where(fn (Builder $w) => $w
                        ->whereIn('status', [Appeal::DONE, Appeal::REJECTED])->orWhereNull('due_at')->orWhere('due_at', '>=', now()))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppeals::route('/'),
            'create' => CreateAppeal::route('/create'),
            'view' => ViewAppeal::route('/{record}'),
        ];
    }
}
