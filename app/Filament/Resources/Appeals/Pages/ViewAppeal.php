<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Models\Appeal;
use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\Appeals\AppealResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;

class ViewAppeal extends ViewRecord
{
    protected static string $resource = AppealResource::class;

    public function getTitle(): string
    {
        return $this->appeal()->number.' · '.$this->appeal()->title;
    }

    private function appeal(): Appeal
    {
        $record = $this->getRecord();
        assert($record instanceof Appeal);

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
        return app(AuthorizationService::class)->can($this->actor(), $code, $this->appeal());
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
            $this->record = $this->appeal()->fresh() ?? $this->appeal();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $a = fn (string $key): string => __('admin.appeals.'.$key);
        $appeals = fn (): ManageAppeals => app(ManageAppeals::class);
        $status = fn (string $to, ?string $resolution = null) => $this->run(fn () => $appeals()->changeStatus($this->actor(), $this->appeal(), $to, $resolution));

        return [
            Action::make('start')->label($a('start'))->icon('heroicon-o-play')
                ->visible(fn (): bool => $this->appeal()->status === Appeal::NEW && $this->may('appeals.close'))
                ->action(fn () => $status(Appeal::IN_PROGRESS)),
            Action::make('done')->label($a('done'))->icon('heroicon-o-check')->color('success')
                ->visible(fn (): bool => ! $this->appeal()->isClosed() && $this->may('appeals.close'))
                ->schema([Textarea::make('resolution')->label($a('resolution'))->rows(3)])
                ->action(fn (array $data) => $status(Appeal::DONE, $data['resolution'] ?? null)),
            ActionGroup::make([
                Action::make('assign')->label($a('assign'))->icon('heroicon-o-user')
                    ->visible(fn (): bool => $this->may('appeals.assign'))
                    ->fillForm(fn (): array => ['responsible_person_id' => $this->appeal()->responsible_person_id])
                    ->schema([
                        Select::make('responsible_person_id')->label($a('responsible'))->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                            ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $appeals()->assign($this->actor(), $this->appeal(), isset($data['responsible_person_id']) ? (int) $data['responsible_person_id'] : null))),
                Action::make('prioritize')->label($a('change_priority'))->icon('heroicon-o-flag')
                    ->visible(fn (): bool => ! $this->appeal()->isClosed() && $this->may('appeals.assign'))
                    ->fillForm(fn (): array => ['priority_code' => $this->appeal()->priority_code])
                    ->schema([Select::make('priority_code')->label($a('priority'))->options(fn (): array => Options::catalog('appeal_priorities'))->required()])
                    ->action(fn (array $data) => $this->run(fn () => $appeals()->prioritize($this->actor(), $this->appeal(), (string) $data['priority_code']))),
                Action::make('createTask')->label($a('create_task'))->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (): bool => ! $this->appeal()->isClosed() && $this->may('appeals.close') && TaskResource::canCreate())
                    ->fillForm(fn (): array => ['title' => $this->appeal()->title, 'type_code' => 'request'])
                    ->schema([
                        TextInput::make('title')->label(__('admin.tasks.title'))->required()->maxLength(255),
                        Select::make('type_code')->label(__('admin.tasks.type'))->options(fn (): array => Options::catalog('task_types'))->required(),
                        DateTimePicker::make('due_at')->label(__('admin.tasks.due'))->seconds(false),
                        Select::make('assignees')->label(__('admin.tasks.assignees'))->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => TaskResource::assignableSearch($search)),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $appeals()->createTask($this->actor(), $this->appeal(), [
                        'title' => (string) $data['title'], 'type_code' => (string) $data['type_code'], 'due_at' => $data['due_at'] ?? null,
                    ], array_map('intval', (array) ($data['assignees'] ?? []))))),
                Action::make('linkTask')->label($a('link_task'))->icon('heroicon-o-link')
                    ->visible(fn (): bool => $this->may('appeals.close'))
                    ->schema([
                        Select::make('task_id')->label(__('admin.tasks.singular'))->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search): array => app(AuthorizationService::class)
                                ->scopeQuery($this->actor(), 'tasks.read', Task::query())->where('title', 'like', "%{$search}%")->limit(20)->pluck('title', 'id')->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $appeals()->linkTask($this->actor(), $this->appeal(), Task::query()->findOrFail($data['task_id'])))),
                Action::make('reject')->label($a('reject'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => ! $this->appeal()->isClosed() && $this->may('appeals.close'))
                    ->schema([Textarea::make('resolution')->label($a('rejection_reason'))->rows(3)->required()])
                    ->action(fn (array $data) => $status(Appeal::REJECTED, (string) $data['resolution'])),
                Action::make('reopen')->label($a('reopen'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (): bool => $this->appeal()->isClosed() && $this->may('appeals.assign'))
                    ->requiresConfirmation()
                    ->action(fn () => $status(Appeal::IN_PROGRESS)),
            ])->label(__('admin.users.actions'))->button()->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $appeal = $this->appeal()->loadMissing(['person', 'responsible']);
        $a = fn (string $key): string => __('admin.appeals.'.$key);
        $tasks = app(AuthorizationService::class)->scopeQuery($this->actor(), 'tasks.read', $appeal->tasks()->getQuery())->get();
        $statuses = Options::catalog('task_statuses');

        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                    ->state(__('crm.appeal_statuses.'.$appeal->status))->color(AppealResource::statusColor($appeal->status)),
                TextEntry::make('type')->label($a('type'))->state(Options::catalog('appeal_types')[$appeal->type_code] ?? $appeal->type_code),
                TextEntry::make('priority')->label($a('priority'))->badge()->color('gray')
                    ->state(Options::catalog('appeal_priorities')[$appeal->priority_code] ?? $appeal->priority_code),
                TextEntry::make('first_response')->label($a('first_response'))
                    ->state($appeal->first_responded_at?->isoFormat('LLL') ?? $appeal->first_response_due_at?->isoFormat('LLL'))->placeholder('—')
                    ->color($appeal->isFirstResponseOverdue() ? 'danger' : null)
                    ->helperText($appeal->first_responded_at !== null ? $a('first_response_given') : ($appeal->isFirstResponseOverdue() ? $a('first_response_overdue') : $a('first_response_until'))),
                TextEntry::make('due')->label($a('due'))->state($appeal->due_at?->isoFormat('LLL'))->placeholder('—')
                    ->color($appeal->isOverdue() ? 'danger' : null)
                    ->helperText($appeal->isOverdue() ? __('admin.tasks.overdue') : null),
                TextEntry::make('person')->label($a('person'))->state($appeal->person?->fullName())->placeholder('—')
                    ->url($appeal->person !== null && PersonResource::canView($appeal->person) ? PersonResource::getUrl('view', ['record' => $appeal->person]) : null),
                TextEntry::make('responsible')->label($a('responsible'))->state($appeal->responsible?->fullName())->placeholder('—'),
                TextEntry::make('source')->label(__('admin.people.source'))->placeholder('—')
                    ->state($appeal->source_code !== null ? (Options::catalog('contact_sources')[$appeal->source_code] ?? $appeal->source_code) : null),
                TextEntry::make('unit')->label(__('admin.org_units.singular'))->state(Places::unitName($appeal->org_unit_id))->placeholder('—'),
                TextEntry::make('territory')->label(__('admin.territories.singular'))->state(Places::territoryName($appeal->territory_id))->placeholder('—'),
                TextEntry::make('created')->label(__('admin.fields.created_at'))->state($appeal->created_at->isoFormat('LLL')),
                TextEntry::make('body')->label($a('body'))->state($appeal->body)->placeholder('—')->columnSpanFull(),
                TextEntry::make('resolution')->label($a('resolution'))->state($appeal->resolution)->visible($appeal->resolution !== null)->columnSpanFull(),
            ]),
            Section::make($a('tasks'))->visible($tasks->isNotEmpty())->schema([
                RepeatableEntry::make('tasks')->hiddenLabel()->columns(2)
                    ->state($tasks->map(fn (Task $task): array => ['title' => $task->title, 'status' => $statuses[$task->status_code] ?? $task->status_code])->all())
                    ->schema([TextEntry::make('title')->hiddenLabel()->weight('bold'), TextEntry::make('status')->hiddenLabel()->badge()->color('gray')]),
            ]),
        ]);
    }
}
