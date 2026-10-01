<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectPhase;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Domain\Tasks\Models\TimeEntry;
use App\Domain\Tasks\TaskWorkflow;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Options;
use App\Livewire\TaskDiscussion;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    private function task(): Task
    {
        $record = $this->getRecord();
        assert($record instanceof Task);

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
        return app(AuthorizationService::class)->can($this->actor(), $code, $this->task());
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
            $this->record = $this->task()->fresh() ?? $this->task();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $statuses = Options::catalog('task_statuses');
        $f = fn (string $key): string => __('admin.tasks.'.$key);

        return [
            Action::make('status')->label($f('change_status'))->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->may('tasks.status.change') && app(TaskWorkflow::class)->nextStatuses($this->task()) !== [])
                ->schema([
                    Select::make('to')->label(__('admin.fields.status'))->required()->live()
                        ->options(fn (): array => collect(app(TaskWorkflow::class)->nextStatuses($this->task()))
                            ->mapWithKeys(fn (string $s): array => [$s => $statuses[$s] ?? $s])->all()),
                    Textarea::make('note')->label($f('status_note'))
                        ->helperText(fn ($get): ?string => $get('to') ? __('admin.tasks.requires.'.app(TaskWorkflow::class)->requires($this->task()->status_code, (string) $get('to'))) : null)
                        ->visible(fn ($get): bool => $get('to') !== null && ! in_array(app(TaskWorkflow::class)->requires($this->task()->status_code, (string) $get('to')), ['none', 'date'], true)),
                    DatePicker::make('resume')->label($f('resume_on'))->minDate(now())
                        ->visible(fn ($get): bool => $get('to') === 'on_hold'),
                    TextInput::make('blocked_by')->label($f('blocked_by'))->visible(fn ($get): bool => $get('to') === 'blocked'),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageTasks::class)->changeStatus(
                    $this->actor(), $this->task(), (string) $data['to'],
                    ($data['to'] ?? null) === 'on_hold' ? ($data['resume'] ?? null) : ($data['note'] ?? null),
                    $data['blocked_by'] ?? null,
                ))),
            ActionGroup::make([
                Action::make('edit')->label($f('edit'))
                    ->visible(fn (): bool => $this->may('tasks.update'))
                    ->fillForm(fn (): array => $this->task()->only(['title', 'description', 'priority_code', 'due_at', 'estimate_minutes', 'phase_id']))
                    ->schema([
                        TextInput::make('title')->label($f('title'))->required()->maxLength(255),
                        Textarea::make('description')->label($f('description'))->rows(4),
                        Select::make('priority_code')->label($f('priority'))->options(fn (): array => Options::catalog('task_priorities'))->required(),
                        DateTimePicker::make('due_at')->label($f('due'))->seconds(false),
                        TextInput::make('estimate_minutes')->label($f('estimate'))->numeric()->integer(),
                        Select::make('phase_id')->label(__('admin.projects.phase'))
                            ->options(fn (): array => $this->task()->project_id ? ProjectPhase::query()->where('project_id', $this->task()->project_id)->pluck('name', 'id')->all() : []),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTasks::class)->update($this->actor(), $this->task(), $data))),
                Action::make('people')->label($f('people'))
                    ->visible(fn (): bool => $this->may('tasks.assign'))
                    ->fillForm(fn (): array => [
                        'assignees' => TaskResource::peopleOf($this->task(), TaskPerson::ASSIGNEE),
                        'watchers' => TaskResource::peopleOf($this->task(), TaskPerson::WATCHER),
                    ])
                    ->schema([
                        Select::make('assignees')->label($f('assignees'))->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => TaskResource::assignableSearch($search))
                            ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all()),
                        Select::make('watchers')->label($f('watchers'))->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => TaskResource::assignableSearch($search))
                            ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTasks::class)->assign(
                        $this->actor(), $this->task(), array_map('intval', (array) ($data['assignees'] ?? [])), array_map('intval', (array) ($data['watchers'] ?? [])),
                    ))),
                Action::make('checklist')->label($f('add_checklist'))
                    ->visible(fn (): bool => $this->may('tasks.update'))
                    ->schema([
                        TextInput::make('title')->label($f('title'))->required()->maxLength(255),
                        Select::make('responsible')->label($f('responsible'))
                            ->options(fn (): array => Person::query()->whereKey(TaskResource::peopleOf($this->task(), TaskPerson::ASSIGNEE))->get()
                                ->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTasks::class)->addChecklistItem(
                        $this->actor(), $this->task(), (string) $data['title'], isset($data['responsible']) ? (int) $data['responsible'] : null,
                    ))),
                Action::make('toggleChecklist')->label($f('toggle'))
                    ->visible(fn (): bool => ChecklistItem::query()->where('task_id', $this->task()->id)->exists() && $this->may('tasks.read'))
                    ->schema([Select::make('item')->label($f('checklist'))->required()
                        ->options(fn (): array => ChecklistItem::query()->where('task_id', $this->task()->id)->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (ChecklistItem $i): array => [$i->id => ($i->done_at ? '✓ ' : '○ ').$i->title])->all())])
                    ->action(function (array $data): void {
                        $item = ChecklistItem::query()->where('task_id', $this->task()->id)->findOrFail($data['item']);
                        $this->run(fn () => app(ManageTasks::class)->toggleChecklistItem($this->actor(), $item, $item->done_at === null));
                    }),
                Action::make('dependency')->label($f('add_dependency'))
                    ->visible(fn (): bool => $this->may('tasks.update'))
                    ->schema([Select::make('depends_on')->label($f('depends_on'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => app(AuthorizationService::class)->scopeQuery($this->actor(), 'tasks.read', Task::query())
                            ->where('title', 'like', "%{$search}%")->whereKeyNot($this->task()->id)->limit(20)->pluck('title', 'id')->all())])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTasks::class)->addDependency(
                        $this->actor(), $this->task(), Task::query()->findOrFail($data['depends_on']),
                    ))),
                Action::make('time')->label($f('log_time'))
                    ->visible(fn (): bool => $this->may('tasks.time.log'))
                    ->schema([
                        TextInput::make('minutes')->label($f('minutes'))->numeric()->integer()->minValue(1)->required(),
                        DatePicker::make('spent_on')->label($f('spent_on'))->default(now())->required()->maxDate(now()),
                        TextInput::make('note')->label($f('status_note'))->maxLength(255),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTasks::class)->logTime(
                        $this->actor(), $this->task(), (int) $data['minutes'], Carbon::parse($data['spent_on']), $data['note'] ?? null,
                    ))),
                Action::make('subtask')->label($f('add_subtask'))
                    ->visible(fn (): bool => app(AuthorizationService::class)->can($this->actor(), 'tasks.create'))
                    ->url(fn (): string => TaskResource::getUrl('create', ['parent' => $this->task()->id])),
                Action::make('delete')->label($f('delete'))->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->may('tasks.delete'))
                    ->action(function (): void {
                        $this->run(fn () => app(ManageTasks::class)->delete($this->actor(), $this->task()));
                        $this->redirect(TaskResource::getUrl('index'));
                    }),
            ])->label(__('admin.users.actions'))->button(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $task = $this->task();
        $statuses = Options::catalog('task_statuses');
        $names = fn (string $role): array => Person::query()->whereKey(TaskResource::peopleOf($task, $role))->get()->map(fn (Person $p): string => $p->fullName())->all();
        $canSeeOthersTime = app(AuthorizationService::class)->can($this->actor(), 'tasks.time.read_others', $task);

        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(3)->schema([
                TextEntry::make('status_code')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $statuses[$state] ?? $state)
                    ->color(fn (string $state): string => TaskResource::statusColor($state)),
                TextEntry::make('priority_code')->label(__('admin.tasks.priority'))->formatStateUsing(fn (string $state): string => Options::catalog('task_priorities')[$state] ?? $state),
                TextEntry::make('type_code')->label(__('admin.tasks.type'))->formatStateUsing(fn (string $state): string => Options::catalog('task_types')[$state] ?? $state),
                TextEntry::make('due_at')->label(__('admin.tasks.due'))->dateTime('d.m.Y H:i')->placeholder('—')
                    ->color(fn (): ?string => $task->isOverdue() ? 'danger' : null)
                    ->suffix(fn (): string => $task->isOverdue() ? ' · '.__('admin.tasks.overdue') : ''),
                TextEntry::make('creator')->label(__('admin.tasks.creator'))->state($task->creator->fullName()),
                TextEntry::make('assignees')->label(__('admin.tasks.assignees'))->badge()->state($names(TaskPerson::ASSIGNEE))->placeholder('—'),
                TextEntry::make('watchers')->label(__('admin.tasks.watchers'))->badge()->state($names(TaskPerson::WATCHER))->placeholder('—'),
                TextEntry::make('subject')->label(__('admin.tasks.subject'))->state($task->subject?->fullName())->placeholder('—'),
                TextEntry::make('project')->label(__('admin.projects.singular'))->placeholder('—')
                    ->state($task->project_id !== null ? Project::query()->whereKey($task->project_id)->value('name') : null),
                TextEntry::make('blocked')->label(__('admin.tasks.blocked_reason'))->visible($task->status_code === 'blocked')
                    ->state(trim(($task->blocked_reason ?? '').' — '.($task->blocked_by ?? ''), ' —')),
                TextEntry::make('on_hold_until')->label(__('admin.tasks.resume_on'))->visible($task->status_code === 'on_hold')->date(),
                TextEntry::make('cancel_reason')->label(__('admin.tasks.cancel_reason'))->visible($task->status_code === 'canceled'),
                TextEntry::make('description')->label(__('admin.tasks.description'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('admin.tasks.checklist'))->columnSpan(1)->schema([
                RepeatableEntry::make('checklist_items')->hiddenLabel()->placeholder('—')
                    ->state(ChecklistItem::query()->with('responsible')->where('task_id', $task->id)->orderBy('sort_order')->get()
                        ->map(fn (ChecklistItem $i): array => [
                            'id' => $i->id,
                            'title' => ($i->done_at ? '✓ ' : '○ ').$i->title,
                            'who' => $i->responsible?->fullName(),
                        ])->all())
                    ->schema([
                        TextEntry::make('title')->hiddenLabel()->weight('medium'),
                        TextEntry::make('who')->hiddenLabel()->size('xs')->color('gray')->placeholder(''),
                    ]),
            ]),
            Section::make(__('admin.tasks.structure'))->columnSpan(2)->collapsible()->schema([
                TextEntry::make('parent')->label(__('admin.tasks.parent'))->placeholder('—')
                    ->state($task->parent?->title)
                    ->url($task->parent_id !== null ? TaskResource::getUrl('view', ['record' => $task->parent_id]) : null),
                TextEntry::make('subtasks')->label(__('admin.tasks.subtasks'))->placeholder('—')->listWithLineBreaks()->bulleted()
                    ->state(Task::query()->notDeleted()->where('parent_id', $task->id)->get()
                        ->map(fn (Task $t): string => $t->title.' — '.($statuses[$t->status_code] ?? $t->status_code))->all()),
                TextEntry::make('depends_on')->label(__('admin.tasks.depends_on'))->badge()->placeholder('—')
                    ->state($task->dependsOn()->get()->map(fn (Task $t): string => $t->title.' ('.($statuses[$t->status_code] ?? $t->status_code).')')->all()),
            ]),
            Section::make(__('admin.tasks.time'))->columnSpan(1)->collapsible()->schema([
                RepeatableEntry::make('time_entries')->hiddenLabel()->placeholder('—')->columns(2)
                    ->state(TimeEntry::query()->with('person')->where('task_id', $task->id)
                        ->when(! $canSeeOthersTime, fn ($q) => $q->where('person_id', $this->actor()->person_id))
                        ->latest('spent_on')->get()
                        ->map(fn (TimeEntry $e): array => ['who' => $e->person->fullName().' · '.$e->spent_on->isoFormat('L'), 'minutes' => intdiv($e->minutes, 60).'h '.($e->minutes % 60).'m'])->all())
                    ->schema([TextEntry::make('who')->hiddenLabel()->size('xs'), TextEntry::make('minutes')->hiddenLabel()]),
            ]),
            Section::make(__('admin.tasks.discussion'))->columnSpanFull()->schema([
                Livewire::make(TaskDiscussion::class, ['taskId' => $task->id])->key('discussion-'.$task->id),
            ]),
        ]);
    }
}
