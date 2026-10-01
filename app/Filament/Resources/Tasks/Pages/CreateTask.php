<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Filament\Resources\Tasks\TaskResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();
        // "Add a subtask" / "task in this project" links pre-fill the placement.
        $this->form->fill(array_filter([
            'parent_id' => request()->integer('parent') ?: null,
            'project_id' => request()->integer('project') ?: null,
            'type_code' => 'assignment',
            'priority_code' => 'medium',
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        $recurrence = filled($data['recurrence_freq'] ?? null)
            ? ['freq' => (string) $data['recurrence_freq'], 'interval' => max(1, (int) ($data['recurrence_interval'] ?? 1))]
            : null;

        try {
            return app(ManageTasks::class)->create($actor, [
                'title' => (string) $data['title'],
                'type_code' => (string) $data['type_code'],
                'priority_code' => (string) $data['priority_code'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'estimate_minutes' => isset($data['estimate_minutes']) ? (int) $data['estimate_minutes'] : null,
                'subject_person_id' => isset($data['subject_person_id']) ? (int) $data['subject_person_id'] : null,
                'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
                'phase_id' => isset($data['phase_id']) ? (int) $data['phase_id'] : null,
                'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
                'org_unit_id' => isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : null,
                'recurrence' => $recurrence,
            ], array_map('intval', (array) ($data['assignees'] ?? [])), array_map('intval', (array) ($data['watchers'] ?? [])));
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
