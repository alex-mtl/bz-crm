<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Discussions;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Actions\ManageProjects;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use App\Domain\Projects\Models\ProjectPhase;
use App\Domain\Projects\ProjectDashboard;
use App\Filament\Pages\Messenger;
use App\Filament\Pages\ProjectGantt;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    private function project(): Project
    {
        $record = $this->getRecord();
        assert($record instanceof Project);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function may(string $code): bool
    {
        return app(AuthorizationService::class)->can($this->actor(), $code, $this->project());
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
            $this->record = $this->project()->fresh() ?? $this->project();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $projects = app(ManageProjects::class);
        $phases = fn (): array => ProjectPhase::query()->where('project_id', $this->project()->id)->orderBy('sort_order')->pluck('name', 'id')->all();

        return [
            Action::make('tasks')->label(__('admin.tasks.plural'))->color('gray')->url(fn (): string => ProjectResource::tasksUrl($this->project())),
            Action::make('gantt')->label(__('admin.views.gantt'))->color('gray')->url(fn (): string => ProjectGantt::getUrl(['project' => $this->project()->id])),
            // The chat of the project (ФО §6.6.2): read and written by those who may read the project.
            Action::make('discussion')->label(__('messaging.ui.types.subject'))->color('gray')->icon('heroicon-o-chat-bubble-left-right')
                ->action(fn () => redirect(Messenger::getUrl(['chat' => app(Discussions::class)->forSubject($this->project())->id]))),
            Action::make('newTask')->label(__('admin.projects.new_task'))
                ->visible(fn (): bool => app(AuthorizationService::class)->can($this->actor(), 'tasks.create'))
                ->url(fn (): string => TaskResource::getUrl('create', ['project' => $this->project()->id])),
            ActionGroup::make([
                Action::make('status')->label(__('admin.projects.change_status'))
                    ->visible(fn (): bool => $this->may('projects.update'))
                    ->fillForm(fn (): array => $this->project()->only(['status', 'health']))
                    ->schema([
                        Select::make('status')->label(__('admin.fields.status'))->required()
                            ->options(collect(Project::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('projects.statuses.'.$s)])->all()),
                        Select::make('health')->label(__('admin.projects.health'))->required()
                            ->options(collect(Project::HEALTH)->mapWithKeys(fn (string $s): array => [$s => __('projects.health.'.$s)])->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $projects->setStatus($this->actor(), $this->project(), (string) $data['status'], (string) $data['health']))),
                Action::make('members')->label(__('admin.projects.members'))
                    ->visible(fn (): bool => $this->may('projects.members.manage'))
                    ->fillForm(fn (): array => ['members' => ProjectMember::query()->where('project_id', $this->project()->id)->where('role', '!=', 'manager')->pluck('person_id')->all()])
                    ->schema([Select::make('members')->label(__('admin.projects.members'))->multiple()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                        ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all())])
                    ->action(fn (array $data) => $this->run(fn () => $projects->setMembers($this->actor(), $this->project(), array_map('intval', (array) ($data['members'] ?? []))))),
                Action::make('addPhase')->label(__('admin.projects.add_phase'))
                    ->visible(fn (): bool => $this->may('projects.update'))
                    ->schema([
                        TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(200),
                        DatePicker::make('starts_on')->label(__('admin.projects.starts_on')),
                        DatePicker::make('due_on')->label(__('admin.projects.due_on')),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $projects->addPhase($this->actor(), $this->project(), [
                        'name' => (string) $data['name'], 'starts_on' => $data['starts_on'] ?? null, 'due_on' => $data['due_on'] ?? null,
                    ]))),
                Action::make('phaseStatus')->label(__('admin.projects.phase_status'))
                    ->visible(fn (): bool => $this->may('projects.update') && $phases() !== [])
                    ->schema([
                        Select::make('phase')->label(__('admin.projects.phase'))->options($phases)->required(),
                        Select::make('status')->label(__('admin.fields.status'))->required()
                            ->options(collect(ProjectPhase::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('projects.phase_statuses.'.$s)])->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $projects->setPhaseStatus($this->actor(), ProjectPhase::query()->findOrFail($data['phase']), (string) $data['status']))),
                Action::make('dependency')->label(__('admin.projects.phase_dependency'))
                    ->visible(fn (): bool => $this->may('projects.update') && count($phases()) > 1)
                    ->schema([
                        Select::make('phase')->label(__('admin.projects.phase'))->options($phases)->required(),
                        Select::make('after')->label(__('admin.tasks.depends_on'))->options($phases)->required(),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $projects->addPhaseDependency(
                        $this->actor(), ProjectPhase::query()->findOrFail($data['phase']), ProjectPhase::query()->findOrFail($data['after']),
                    ))),
                Action::make('budget')->label(__('admin.projects.budget'))
                    ->visible(fn (): bool => $this->may('projects.budget.manage'))
                    ->fillForm(fn (): array => $this->project()->only(['budget_plan', 'budget_fact']))
                    ->schema([
                        TextInput::make('budget_plan')->label(__('admin.projects.budget_plan'))->numeric(),
                        TextInput::make('budget_fact')->label(__('admin.projects.budget_fact'))->numeric(),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $projects->setBudget($this->actor(), $this->project(),
                        isset($data['budget_plan']) ? (float) $data['budget_plan'] : null, isset($data['budget_fact']) ? (float) $data['budget_fact'] : null))),
                Action::make('resource')->label(__('admin.projects.add_resource'))
                    ->visible(fn (): bool => $this->may('projects.budget.manage'))
                    ->schema([
                        Select::make('kind_code')->label(__('admin.projects.resource_kind'))->options(fn (): array => Options::catalog('resource_kinds'))->required(),
                        TextInput::make('description')->label(__('admin.tasks.description'))->required()->maxLength(255),
                        Select::make('phase_id')->label(__('admin.projects.phase'))->options($phases),
                        TextInput::make('plan_amount')->label(__('admin.projects.plan'))->numeric(),
                        TextInput::make('fact_amount')->label(__('admin.projects.fact'))->numeric(),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $projects->addResource($this->actor(), $this->project(), [
                        'kind_code' => (string) $data['kind_code'], 'description' => (string) $data['description'],
                        'phase_id' => isset($data['phase_id']) ? (int) $data['phase_id'] : null,
                        'plan_amount' => isset($data['plan_amount']) ? (float) $data['plan_amount'] : null,
                        'fact_amount' => isset($data['fact_amount']) ? (float) $data['fact_amount'] : null,
                    ]))),
                Action::make('archive')->label(__('admin.org_units.archive'))->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->project()->archived_at === null && $this->may('projects.archive'))
                    ->action(fn () => $this->run(fn () => $projects->archive($this->actor(), $this->project()))),
            ])->label(__('admin.users.actions'))->button(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $summary = app(ProjectDashboard::class)->summary($project);
        $seesBudget = $this->may('projects.budget.read');
        $kinds = Options::catalog('resource_kinds');

        return $schema->columns(4)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('progress')->label(__('admin.projects.progress'))->state($summary['progress'].'% ('.$summary['done'].' / '.$summary['total'].')')->size('lg')->weight('bold'),
                TextEntry::make('overdue')->label(__('admin.tasks.overdue'))->state((string) $summary['overdue'])->size('lg')->weight('bold')
                    ->color($summary['overdue'] > 0 ? 'danger' : 'success'),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('projects.statuses.'.$state)),
                TextEntry::make('health')->label(__('admin.projects.health'))->badge()->formatStateUsing(fn (string $state): string => __('projects.health.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'off_track' => 'danger', 'at_risk' => 'warning', default => 'success'
                    }),
                TextEntry::make('manager')->label(__('projects.member_roles.manager'))->state($project->manager->fullName()),
                TextEntry::make('dates')->label(__('admin.projects.dates'))
                    ->state(($project->starts_on?->isoFormat('L') ?? '…').' — '.($project->due_on?->isoFormat('L') ?? '…')),
                TextEntry::make('structure')->label(__('admin.projects.structure'))->state(__('projects.structure.'.$project->structure)
                    .($project->strict_phases ? ' · '.__('admin.projects.strict_phases') : '')),
                TextEntry::make('visibility')->label(__('admin.projects.visibility'))->formatStateUsing(fn (string $state): string => __('projects.visibility.'.$state)),
                TextEntry::make('description')->label(__('admin.tasks.description'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('admin.projects.phases'))->columnSpan(2)->schema([
                RepeatableEntry::make('phase_list')->hiddenLabel()->placeholder('—')->columns(3)
                    ->state($project->phases()->with(['responsible', 'dependsOn'])->get()->map(fn (ProjectPhase $p): array => [
                        'name' => $p->name,
                        'status' => __('projects.phase_statuses.'.$p->status),
                        'dates' => ($p->starts_on?->isoFormat('L') ?? '…').' — '.($p->due_on?->isoFormat('L') ?? '…'),
                    ])->all())
                    ->schema([TextEntry::make('name')->hiddenLabel()->weight('medium'), TextEntry::make('status')->hiddenLabel()->badge(), TextEntry::make('dates')->hiddenLabel()]),
            ]),
            Section::make(__('admin.projects.workload'))->columnSpan(2)->schema([
                RepeatableEntry::make('workload')->hiddenLabel()->placeholder('—')->columns(3)
                    ->state(array_map(fn (array $w): array => [
                        'name' => $w['name'], 'open' => __('admin.projects.open_tasks', ['n' => $w['open']]),
                        'time' => intdiv($w['minutes'], 60).'h '.($w['minutes'] % 60).'m',
                    ], $summary['workload']))
                    ->schema([TextEntry::make('name')->hiddenLabel(), TextEntry::make('open')->hiddenLabel(), TextEntry::make('time')->hiddenLabel()]),
            ]),
            Section::make(__('admin.projects.budget'))->columnSpanFull()->visible($seesBudget)->columns(3)->schema([
                TextEntry::make('budget_plan')->label(__('admin.projects.budget_plan'))->state(number_format($summary['budget']['plan'], 2, '.', ' ')),
                TextEntry::make('budget_fact')->label(__('admin.projects.budget_fact'))->state(number_format($summary['budget']['fact'], 2, '.', ' '))
                    ->color($summary['budget']['plan'] > 0 && $summary['budget']['fact'] > $summary['budget']['plan'] ? 'danger' : null),
                TextEntry::make('resources')->label(__('admin.projects.resources'))->listWithLineBreaks()->placeholder('—')
                    ->state(array_map(fn (array $r): string => ($kinds[$r['kind']] ?? $r['kind']).': '.$r['fact'].' / '.$r['plan'], $summary['budget']['resources'])),
            ]),
        ]);
    }
}
