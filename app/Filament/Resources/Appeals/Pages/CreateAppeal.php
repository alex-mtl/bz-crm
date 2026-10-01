<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Pages;

use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Appeals\AppealResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateAppeal extends CreateRecord
{
    protected static string $resource = AppealResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        $int = fn (string $key): ?int => isset($data[$key]) ? (int) $data[$key] : null;

        try {
            return app(ManageAppeals::class)->register($actor, [
                'title' => (string) $data['title'],
                'type_code' => (string) $data['type_code'],
                'body' => $data['body'] ?? null,
                'source_code' => $data['source_code'] ?? null,
                'priority_code' => $data['priority_code'] ?? null,
                'person_id' => $int('person_id'),
                'responsible_person_id' => $int('responsible_person_id'),
                'org_unit_id' => $int('org_unit_id'),
                'territory_id' => $int('territory_id'),
                'due_at' => $data['due_at'] ?? null,
            ]);
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return AppealResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
