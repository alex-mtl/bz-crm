<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\People\Events\PersonSaved;
use App\Domain\People\Exceptions\PeopleRuleViolation;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonStatusHistory;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The people registry (ФО §6.9.1): cards of supporters, partners, candidates and everyone else, created and
 * changed by staff within their scope. A person outside the org structure lives in a territory and belongs to
 * a responsible unit — that is what the scopes "Т" and "П" are checked against.
 */
final readonly class ManagePeople
{
    private const array FIELDS = ['first_name', 'last_name', 'email', 'phone', 'person_type', 'preferred_locale', 'territory_id', 'responsible_unit_id', 'source_code'];

    private const array IDENTIFYING = ['first_name', 'last_name', 'email', 'phone'];

    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra  set by the system, not by the user (e.g. import_batch_id)
     */
    public function create(User $actor, array $data, array $extra = []): Person
    {
        $attributes = $this->validated($data, null);
        $person = new Person([...$attributes, ...$extra]);
        $this->authorization->authorize($actor, 'people.create', $person);

        return DB::transaction(function () use ($actor, $person): Person {
            $person->save();
            $this->history($actor, $person, PersonStatusHistory::TYPE, null, $person->person_type);
            $this->journal->record('people.person.created', $person, [], [
                'person_type' => $person->person_type,
                'territory_id' => $person->territory_id,
                'responsible_unit_id' => $person->responsible_unit_id,
                'source_code' => $person->source_code,
            ]);
            PersonSaved::dispatch($person, true);

            return $person;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Person $person, array $data): Person
    {
        $this->authorization->authorize($actor, 'people.update', $person);
        $attributes = $this->validated($data, $person);

        $moved = (clone $person)->fill($attributes);
        if ($moved->isDirty(['territory_id', 'responsible_unit_id'])) {
            // Moving a card out of one's own scope is giving it away — allowed only into a place one may also manage.
            $this->authorization->authorize($actor, 'people.update', $moved);
        }

        return DB::transaction(function () use ($actor, $person, $attributes): Person {
            $oldType = $person->person_type;
            $person->fill($attributes);
            $dirty = array_keys($person->getDirty());
            if ($dirty === []) {
                return $person;
            }
            $tracked = ['person_type', 'territory_id', 'responsible_unit_id', 'source_code'];
            $old = array_intersect_key($person->getOriginal(), array_flip(array_intersect($tracked, $dirty)));
            $person->save();

            if ($oldType !== $person->person_type) {
                $this->history($actor, $person, PersonStatusHistory::TYPE, $oldType, $person->person_type);
            }
            // Names and contacts are not copied into the journal — only the fact that they changed.
            $this->journal->record('people.person.updated', $person, $old, [
                ...array_intersect_key($person->getAttributes(), $old),
                'fields' => $dirty,
            ]);
            if (array_intersect(self::IDENTIFYING, $dirty) !== []) {
                PersonSaved::dispatch($person, false);
            }

            return $person;
        });
    }

    public function archive(User $actor, Person $person, ?string $note = null): void
    {
        $this->authorization->authorize($actor, 'people.archive', $person);
        if ($person->isArchived()) {
            return;
        }
        if ($person->user?->isActive() === true) {
            throw PeopleRuleViolation::because('has_active_account');
        }

        DB::transaction(function () use ($actor, $person, $note): void {
            $person->forceFill(['archived_at' => now()])->save();
            $this->history($actor, $person, PersonStatusHistory::ARCHIVED, null, null, $note);
            $this->journal->record('people.person.archived', $person, [], array_filter(['note' => $note]));
        });
    }

    public function restore(User $actor, Person $person): void
    {
        $this->authorization->authorize($actor, 'people.archive', $person);
        if (! $person->isArchived()) {
            return;
        }
        if ($person->duplicate_of_person_id !== null) {
            throw PeopleRuleViolation::because('merged_card');
        }

        DB::transaction(function () use ($actor, $person): void {
            $person->forceFill(['archived_at' => null])->save();
            $this->history($actor, $person, PersonStatusHistory::RESTORED);
            $this->journal->record('people.person.restored', $person);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validated(array $data, ?Person $existing): array
    {
        $attributes = array_intersect_key($data, array_flip(self::FIELDS));

        foreach (['first_name', 'last_name', 'source_code'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = filled($attributes[$key]) ? trim((string) $attributes[$key]) : null;
            }
        }
        if (($existing === null || array_key_exists('first_name', $attributes)) && blank($attributes['first_name'] ?? null)) {
            throw PeopleRuleViolation::because('first_name_required');
        }

        if (array_key_exists('email', $attributes)) {
            $email = filled($attributes['email']) ? Str::lower(trim((string) $attributes['email'])) : null;
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw PeopleRuleViolation::because('invalid_email');
            }
            $attributes['email'] = $email;
        }
        if (array_key_exists('phone', $attributes)) {
            $phone = PhoneNumber::normalize(filled($attributes['phone']) ? (string) $attributes['phone'] : null);
            if (filled($attributes['phone']) && $phone === null) {
                throw PeopleRuleViolation::because('invalid_phone');
            }
            $attributes['phone'] = $phone;
        }

        $type = $attributes['person_type'] ?? $existing?->person_type;
        $typeChanged = $existing === null || $type !== $existing->person_type;
        if ($type === null || ($typeChanged && ! CatalogItem::query()->ofCatalog('person_types')->selectable()->where('code', $type)->exists())) {
            throw PeopleRuleViolation::because('invalid_person_type');
        }
        if (isset($attributes['preferred_locale']) && ! in_array($attributes['preferred_locale'], ['ro', 'ru', 'en'], true)) {
            throw PeopleRuleViolation::because('invalid_locale');
        }
        foreach (['territory_id' => 'territories', 'responsible_unit_id' => 'org_units'] as $key => $table) {
            if (isset($attributes[$key]) && ! DB::table($table)->where('id', $attributes[$key])->exists()) {
                throw PeopleRuleViolation::because('invalid_'.$key);
            }
        }

        return $attributes;
    }

    private function history(User $actor, Person $person, string $kind, ?string $old = null, ?string $new = null, ?string $note = null): void
    {
        PersonStatusHistory::query()->create([
            'person_id' => $person->id, 'kind' => $kind, 'old_value' => $old, 'new_value' => $new,
            'note' => $note, 'changed_by_user_id' => $actor->id,
        ]);
    }
}
