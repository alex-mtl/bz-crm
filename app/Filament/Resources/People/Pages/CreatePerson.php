<?php

declare(strict_types=1);

namespace App\Filament\Resources\People\Pages;

use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ManagePeople;
use App\Filament\Resources\People\PersonResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A new card in the people registry (ФО §6.9.1) — a supporter, a partner, a candidate. People with accounts
 * arrive through invitations and applications, not here.
 */
class CreatePerson extends CreateRecord
{
    protected static string $resource = PersonResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);

        try {
            return DB::transaction(function () use ($actor, $data): Model {
                $person = app(ManagePeople::class)->create($actor, $data);
                $custom = array_filter((array) ($data['custom'] ?? []), fn ($value): bool => $value !== null && $value !== '');
                if ($custom !== []) {
                    app(CustomFields::class)->store(CustomField::PERSON, $person->id, $custom, $person->person_type);
                }

                return $person;
            });
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return PersonResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
