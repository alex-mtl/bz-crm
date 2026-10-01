<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Enums\FieldVisibility;
use App\Domain\Profiles\Exceptions\ProfileRuleViolation;
use App\Domain\Profiles\Models\PersonContact;
use App\Domain\Profiles\Models\PersonProfile;
use Illuminate\Support\Facades\DB;

/**
 * The owner fills the open layer and chooses who sees each field (ФО §6.3.1, Д-13).
 */
final readonly class ManageProfile
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array{photo_path?: string|null, cover_code?: string|null, bio?: string|null, birth_date?: string|null, gender?: string|null,
     *               personal_visibility?: string, skills?: list<string>|null, interests?: list<string>|null, languages?: list<string>|null,
     *               skills_visibility?: string}  $data
     * @param  list<array{contact_type: string, value: string, visibility: string, is_preferred?: bool}>|null  $contacts  null = leave contacts as they are
     */
    public function update(User $user, Person $person, array $data, ?array $contacts = null): PersonProfile
    {
        $this->authorization->authorize($user, 'profile.own.update', $person);

        return $this->save($person, $data, $contacts);
    }

    /**
     * Staff fill the open layer of a person who has no account of their own — a supporter, a partner, a candidate
     * (ФО §6.9.1). The profile of an account holder stays the owner's (Д-13).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{contact_type: string, value: string, visibility: string, is_preferred?: bool}>|null  $contacts
     */
    public function updateFor(User $actor, Person $person, array $data, ?array $contacts = null): PersonProfile
    {
        $this->authorization->authorize($actor, 'people.update', $person);
        if ($person->user?->isActive() === true) {
            throw ProfileRuleViolation::because('owner_fills_profile');
        }

        // There is no owner to choose who sees the fields, and no "management" or "colleagues" around such a person:
        // the fields are open to everyone whose role may read that field group (the administrator's half of Д-13).
        return $this->save($person, ['personal_visibility' => 'all', 'skills_visibility' => 'all', ...$data], $contacts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{contact_type: string, value: string, visibility: string, is_preferred?: bool}>|null  $contacts
     */
    private function save(Person $person, array $data, ?array $contacts): PersonProfile
    {
        foreach (['personal_visibility', 'skills_visibility'] as $key) {
            if (isset($data[$key]) && FieldVisibility::tryFrom($data[$key]) === null) {
                throw ProfileRuleViolation::because('invalid_visibility');
            }
        }
        if ($contacts !== null) {
            $types = CatalogItem::query()->ofCatalog('contact_types')->selectable()->pluck('code')->all();
            foreach ($contacts as $contact) {
                if (! in_array($contact['contact_type'], $types, true) || FieldVisibility::tryFrom($contact['visibility']) === null || trim($contact['value']) === '') {
                    throw ProfileRuleViolation::because('invalid_contact');
                }
            }
        }

        return DB::transaction(function () use ($person, $data, $contacts): PersonProfile {
            $profile = PersonProfile::query()->firstOrNew(['person_id' => $person->id]);
            $profile->fill($data);
            $changed = array_keys($profile->getDirty());
            $profile->save();

            if ($contacts !== null) {
                PersonContact::query()->where('person_id', $person->id)->delete();
                foreach ($contacts as $i => $contact) {
                    PersonContact::query()->create([
                        'person_id' => $person->id,
                        'contact_type' => $contact['contact_type'],
                        'value' => trim($contact['value']),
                        'visibility' => $contact['visibility'],
                        'is_preferred' => (bool) ($contact['is_preferred'] ?? false),
                        'sort_order' => ($i + 1) * 10,
                    ]);
                }
                $changed[] = 'contacts';
            }

            // Field names only: values of the open layer may have narrow visibility themselves.
            if ($changed !== []) {
                $this->journal->record('profile.open.updated', $person, [], ['fields' => array_values(array_unique($changed))]);
            }

            return $profile;
        });
    }
}
