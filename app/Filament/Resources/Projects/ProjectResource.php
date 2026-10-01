<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects;

use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectTemplate;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\PersonSearch;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Projects (ФО §6.8.1): lifecycle and health are separate axes; the list shows what projects.read allows.
 */
class ProjectResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Project::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.work');
    }

    public static function getModelLabel(): string
    {
        return __('admin.projects.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.projects.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'projects.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('projects.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('projects.read', $record);
    }

    public static function canCreate(): bool
    {
        return static::allows('projects.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(200)->columnSpanFull(),
                Select::make('template_id')->label(__('admin.projects.template'))
                    ->options(fn (): array => ProjectTemplate::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText(__('admin.projects.template_hint')),
                Select::make('org_unit_id')->label(__('admin.org_units.singular'))
                    ->options(fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
                        ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all()),
                Select::make('manager_person_id')->label(__('projects.member_roles.manager'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                Select::make('members')->label(__('admin.projects.members'))->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                    ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all()),
                Select::make('visibility')->label(__('admin.projects.visibility'))->required()->default('members')
                    ->options(['members' => __('projects.visibility.members'), 'organization' => __('projects.visibility.organization')]),
                Toggle::make('strict_phases')->label(__('admin.projects.strict_phases')),
                DatePicker::make('starts_on')->label(__('admin.projects.starts_on')),
                DatePicker::make('due_on')->label(__('admin.projects.due_on')),
                TextInput::make('budget_plan')->label(__('admin.projects.budget_plan'))->numeric(),
                Textarea::make('description')->label(__('admin.tasks.description'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('manager'))
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->wrap(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('projects.statuses.'.$state)),
                TextColumn::make('health')->label(__('admin.projects.health'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('projects.health.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'off_track' => 'danger', 'at_risk' => 'warning', default => 'success'
                    }),
                TextColumn::make('manager')->label(__('projects.member_roles.manager'))->state(fn (Project $record): string => $record->manager->fullName()),
                TextColumn::make('due_on')->label(__('admin.projects.due_on'))->date()->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(Project::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('projects.statuses.'.$s)])->all()),
                SelectFilter::make('health')->label(__('admin.projects.health'))
                    ->options(collect(Project::HEALTH)->mapWithKeys(fn (string $s): array => [$s => __('projects.health.'.$s)])->all()),
                TernaryFilter::make('archived')->label(__('admin.org_units.archived'))->default(false)
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('archived_at'), false: fn (Builder $query) => $query->whereNull('archived_at')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function tasksUrl(Project $project): string
    {
        return TaskResource::getUrl('index', ['tableFilters' => ['project_id' => ['value' => $project->id]], 'activeTab' => 'all']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
        ];
    }
}
