<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectPhase;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasks (ФО §6.8.2–6.8.3): the list shows exactly what tasks.read allows — in SQL, so counters and search agree.
 */
class TaskResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Task::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.work');
    }

    public static function getModelLabel(): string
    {
        return __('admin.tasks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.tasks.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'tasks.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('tasks.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('tasks.read', $record);
    }

    public static function canCreate(): bool
    {
        return static::allows('tasks.create');
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
            'done' => 'success', 'canceled' => 'gray', 'blocked' => 'danger', 'on_hold' => 'warning',
            'in_review' => 'info', 'in_progress' => 'primary', default => 'gray',
        };
    }

    /**
     * People the actor may assign (Д-15: active users only; the actor's reach decides who).
     *
     * @return array<int, string>
     */
    public static function assignableSearch(string $term): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User) {
            return [];
        }

        return collect(PersonSearch::search($term, activeUsersOnly: true))
            ->filter(fn (string $name, int $id): bool => app(AuthorizationService::class)->can($actor, 'tasks.assign', Person::query()->find($id)))
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')->label(__('admin.tasks.title'))->required()->maxLength(255)->columnSpanFull(),
                Select::make('type_code')->label(__('admin.tasks.type'))->options(fn (): array => Options::catalog('task_types'))->required()->default('assignment'),
                Select::make('priority_code')->label(__('admin.tasks.priority'))->options(fn (): array => Options::catalog('task_priorities'))->required()->default('medium'),
                Textarea::make('description')->label(__('admin.tasks.description'))->rows(4)->columnSpanFull(),
                DateTimePicker::make('due_at')->label(__('admin.tasks.due'))->seconds(false),
                TextInput::make('estimate_minutes')->label(__('admin.tasks.estimate'))->numeric()->integer()->minValue(0),
            ]),
            Section::make(__('admin.tasks.people'))->columns(2)->schema([
                Select::make('assignees')->label(__('admin.tasks.assignees'))->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::assignableSearch($search))
                    ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all())
                    ->helperText(__('admin.tasks.assignees_hint')),
                Select::make('watchers')->label(__('admin.tasks.watchers'))->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::assignableSearch($search))
                    ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all()),
                Select::make('subject_person_id')->label(__('admin.tasks.subject'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value))
                    ->helperText(__('admin.tasks.subject_hint')),
            ]),
            Section::make(__('admin.tasks.placement'))->columns(2)->collapsed()->schema([
                Select::make('project_id')->label(__('admin.projects.singular'))->live()
                    ->options(fn (): array => static::scoped(Project::query()->whereNull('archived_at'), 'projects.read')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('phase_id')->label(__('admin.projects.phase'))
                    ->options(fn (Get $get): array => $get('project_id') ? ProjectPhase::query()->where('project_id', $get('project_id'))->orderBy('sort_order')->pluck('name', 'id')->all() : []),
                Select::make('parent_id')->label(__('admin.tasks.parent'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::scoped(Task::query(), 'tasks.read')->where('title', 'like', "%{$search}%")->limit(20)->pluck('title', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Task::query()->whereKey($value)->value('title')),
                Select::make('org_unit_id')->label(__('admin.org_units.singular'))
                    ->options(fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
                        ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all())
                    ->helperText(__('admin.tasks.unit_hint')),
                Grid::make(2)->schema([
                    Select::make('recurrence_freq')->label(__('admin.tasks.recurrence'))
                        ->options(['daily' => __('admin.tasks.freq.daily'), 'weekly' => __('admin.tasks.freq.weekly'), 'monthly' => __('admin.tasks.freq.monthly')]),
                    TextInput::make('recurrence_interval')->label(__('admin.tasks.recurrence_interval'))->numeric()->integer()->minValue(1)->default(1),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $statuses = Options::catalog('task_statuses');
        $priorities = Options::catalog('task_priorities');
        $types = Options::catalog('task_types');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignees']))
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')->label(__('admin.tasks.title'))->searchable()->wrap()
                    ->description(fn (Task $record): ?string => $record->project_id !== null ? Project::query()->whereKey($record->project_id)->value('name') : null),
                TextColumn::make('status_code')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $statuses[$state] ?? $state)
                    ->color(fn (string $state): string => static::statusColor($state)),
                TextColumn::make('priority_code')->label(__('admin.tasks.priority'))
                    ->formatStateUsing(fn (string $state): string => $priorities[$state] ?? $state)->toggleable(),
                TextColumn::make('type_code')->label(__('admin.tasks.type'))
                    ->formatStateUsing(fn (string $state): string => $types[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assignees')->label(__('admin.tasks.assignees'))->placeholder('—')
                    ->state(fn (Task $record): string => $record->assignees->map(fn (Person $p): string => $p->fullName())->implode(', ')),
                TextColumn::make('due_at')->label(__('admin.tasks.due'))->dateTime('d.m.Y H:i')->sortable()->placeholder('—')
                    ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (Task $record): ?string => $record->isOverdue() ? __('admin.tasks.overdue') : null),
            ])
            ->filters([
                SelectFilter::make('status_code')->label(__('admin.fields.status'))->multiple()->options($statuses),
                SelectFilter::make('priority_code')->label(__('admin.tasks.priority'))->options($priorities),
                SelectFilter::make('type_code')->label(__('admin.tasks.type'))->options($types),
                SelectFilter::make('project_id')->label(__('admin.projects.singular'))
                    ->options(fn (): array => static::scoped(Project::query(), 'projects.read')->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('overdue')->label(__('admin.tasks.overdue'))
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status_code', Task::CLOSED), false: fn (Builder $query) => $query->where(fn (Builder $w) => $w->whereNull('due_at')->orWhere('due_at', '>=', now())->orWhereIn('status_code', Task::CLOSED))),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkPriority')->label(__('admin.tasks.bulk_priority'))
                        ->visible(fn (): bool => static::allows('tasks.bulk'))
                        ->schema([Select::make('priority_code')->label(__('admin.tasks.priority'))->options($priorities)->required()])
                        ->action(fn (Collection $records, array $data) => static::bulk($records, ['priority_code' => (string) $data['priority_code']])),
                    BulkAction::make('bulkStatus')->label(__('admin.tasks.bulk_status'))
                        ->visible(fn (): bool => static::allows('tasks.bulk'))
                        ->schema([Select::make('status')->label(__('admin.fields.status'))->options($statuses)->required()])
                        ->action(fn (Collection $records, array $data) => static::bulk($records, ['status' => (string) $data['status']])),
                    BulkAction::make('bulkDue')->label(__('admin.tasks.bulk_due'))
                        ->visible(fn (): bool => static::allows('tasks.bulk'))
                        ->schema([DateTimePicker::make('due_at')->label(__('admin.tasks.due'))->required()->seconds(false)])
                        ->action(fn (Collection $records, array $data) => static::bulk($records, ['due_at' => (string) $data['due_at']])),
                    BulkAction::make('bulkAssign')->label(__('admin.tasks.bulk_assign'))
                        ->visible(fn (): bool => static::allows('tasks.bulk'))
                        ->schema([Select::make('assignees')->label(__('admin.tasks.assignees'))->multiple()->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search): array => static::assignableSearch($search))])
                        ->action(fn (Collection $records, array $data) => static::bulk($records, ['assignee_ids' => array_map('intval', (array) $data['assignees'])])),
                ]),
            ]);
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $change
     */
    protected static function bulk(Collection $records, array $change): void
    {
        $result = app(ManageTasks::class)->bulk(static::actor(), $records->modelKeys(), $change);
        Notification::make()->title(__('admin.tasks.bulk_result', $result))
            ->color($result['refused'] > 0 ? 'warning' : 'success')->send();
    }

    /**
     * @return list<int>
     */
    public static function peopleOf(Task $task, string $role): array
    {
        return TaskPerson::query()->where('task_id', $task->id)->where('role', $role)->pluck('person_id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
        ];
    }
}
