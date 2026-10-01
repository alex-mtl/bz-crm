<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\ManageProjects;
use App\Domain\Projects\Models\ProjectTemplate;
use App\Filament\Resources\Projects\ProjectResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        $template = isset($data['template_id']) ? ProjectTemplate::query()->find($data['template_id']) : null;

        try {
            return app(ManageProjects::class)->create($actor, array_filter([
                'name' => (string) $data['name'],
                'description' => $data['description'] ?? null,
                'org_unit_id' => isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : null,
                'manager_person_id' => isset($data['manager_person_id']) ? (int) $data['manager_person_id'] : null,
                'visibility' => (string) $data['visibility'],
                'strict_phases' => (bool) ($data['strict_phases'] ?? false),
                'starts_on' => $data['starts_on'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'budget_plan' => isset($data['budget_plan']) ? (float) $data['budget_plan'] : null,
            ], fn ($v) => $v !== null), array_map('intval', (array) ($data['members'] ?? [])), $template);
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return ProjectResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
