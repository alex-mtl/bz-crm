<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Filament\Resources\Leads\LeadResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();
        // "New lead" from a pipeline board pre-selects the pipeline.
        $this->form->fill(array_filter(['pipeline_id' => request()->integer('pipeline') ?: null]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        $int = fn (string $key): ?int => isset($data[$key]) ? (int) $data[$key] : null;

        try {
            return app(ManageLeads::class)->create($actor, Pipeline::query()->findOrFail($data['pipeline_id']), Person::query()->findOrFail($data['person_id']), [
                'title' => $data['title'] ?? null,
                'stage_id' => $int('stage_id'),
                'responsible_person_id' => $int('responsible_person_id'),
                'org_unit_id' => $int('org_unit_id'),
                'territory_id' => $int('territory_id'),
                'source_code' => $data['source_code'] ?? null,
            ]);
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return LeadResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
