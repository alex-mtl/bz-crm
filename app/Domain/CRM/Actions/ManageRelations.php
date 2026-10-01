<?php

declare(strict_types=1);

namespace App\Domain\CRM\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\PersonRelation;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relations between people (ФО §6.9.1). A relation is stored once and read from both sides: from the other
 * side it is shown under the inverse type of the catalog ("invited" ↔ "invited by").
 */
final readonly class ManageRelations
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function add(User $actor, Person $person, Person $related, string $relationCode, ?string $note = null): PersonRelation
    {
        $this->authorization->authorize($actor, 'crm.relations.manage', $person);
        // Naming someone in a relation reveals that they exist: the actor must be allowed to see that card too.
        $this->authorization->authorize($actor, 'people.read', $related);

        if ($person->is($related)) {
            throw CrmRuleViolation::because('relation_to_self');
        }
        $type = CatalogItem::query()->ofCatalog('person_relation_types')->selectable()->where('code', $relationCode)->first();
        if ($type === null) {
            throw CrmRuleViolation::because('invalid_relation_type');
        }
        $inverse = (string) $type->property('inverse', $relationCode);
        $exists = PersonRelation::query()
            ->where(fn ($q) => $q->where('person_id', $person->id)->where('related_person_id', $related->id)->where('relation_code', $relationCode))
            ->orWhere(fn ($q) => $q->where('person_id', $related->id)->where('related_person_id', $person->id)->where('relation_code', $inverse))
            ->exists();
        if ($exists) {
            throw CrmRuleViolation::because('relation_exists');
        }

        return DB::transaction(function () use ($actor, $person, $related, $relationCode, $note): PersonRelation {
            $relation = PersonRelation::query()->create([
                'person_id' => $person->id, 'related_person_id' => $related->id, 'relation_code' => $relationCode,
                'note' => filled($note) ? trim((string) $note) : null, 'created_by_user_id' => $actor->id,
            ]);
            $this->journal->record('crm.relation.added', $person, [], ['related_person_id' => $related->id, 'relation' => $relationCode]);

            return $relation;
        });
    }

    public function remove(User $actor, PersonRelation $relation): void
    {
        $this->authorization->authorize($actor, 'crm.relations.manage', $relation->person);

        DB::transaction(function () use ($relation): void {
            $this->journal->record('crm.relation.removed', $relation->person, ['related_person_id' => $relation->related_person_id, 'relation' => $relation->relation_code]);
            $relation->delete();
        });
    }

    /**
     * Relations of a person from both sides, only with people the viewer may see.
     *
     * @return Collection<int, array{relation: PersonRelation, other: Person, type: string}>
     */
    public function of(User $viewer, Person $person): Collection
    {
        $types = CatalogItem::query()->ofCatalog('person_relation_types')->get()->keyBy('code');

        return PersonRelation::query()->with(['person', 'relatedPerson'])
            ->where('person_id', $person->id)->orWhere('related_person_id', $person->id)
            ->orderBy('id')->get()
            ->map(function (PersonRelation $relation) use ($person, $types): array {
                $direct = $relation->person_id === $person->id;
                $code = $direct ? $relation->relation_code : (string) ($types->get($relation->relation_code)?->property('inverse', $relation->relation_code) ?? $relation->relation_code);

                return [
                    'relation' => $relation,
                    'other' => $direct ? $relation->relatedPerson : $relation->person,
                    'type' => $types->get($code)?->name() ?? $code,
                ];
            })
            ->filter(fn (array $row): bool => $this->authorization->can($viewer, 'people.read', $row['other']))
            ->values();
    }
}
