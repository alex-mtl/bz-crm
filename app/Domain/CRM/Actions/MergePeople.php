<?php

declare(strict_types=1);

namespace App\Domain\CRM\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\CRM\Models\PersonMerge;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonStatusHistory;
use App\Domain\People\PersonReferences;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate card into the one that stays (ФО §6.9.1, ТЗ §27). Everything that pointed at the duplicate —
 * tasks, leads, appeals, interactions, relations — now points at the kept card. Nothing is deleted for good:
 * the duplicate stays as an archived card marked "duplicate of", and the merge record keeps what was moved,
 * what was redundant and what the duplicate looked like.
 *
 * Two accounts of one human are a different case — they are linked through the hints of Д-10.
 */
final readonly class MergePeople
{
    private const array FILLABLE_FROM_DUPLICATE = ['last_name', 'email', 'phone', 'territory_id', 'responsible_unit_id', 'source_code'];

    public function __construct(
        private AuthorizationService $authorization,
        private PersonReferences $references,
        private EventJournal $journal,
    ) {}

    public function __invoke(User $actor, Person $kept, Person $duplicate): PersonMerge
    {
        // Both cards must be visible to the one who merges them (catalog §5).
        $this->authorization->authorize($actor, 'crm.people.merge', $kept);
        $this->authorization->authorize($actor, 'crm.people.merge', $duplicate);

        if ($kept->is($duplicate)) {
            throw CrmRuleViolation::because('merge_same_card');
        }
        if ($kept->duplicate_of_person_id !== null || $duplicate->duplicate_of_person_id !== null) {
            throw CrmRuleViolation::because('merge_already_merged');
        }
        if ($duplicate->user !== null) {
            throw CrmRuleViolation::because('merge_duplicate_has_account');
        }
        if (DB::table('org_memberships')->where('person_id', $duplicate->id)->exists()) {
            throw CrmRuleViolation::because('merge_duplicate_in_structure');
        }

        return DB::transaction(function () use ($actor, $kept, $duplicate): PersonMerge {
            $moved = [];
            foreach ($this->references->all() as $reference) {
                $result = $this->repoint($reference, $kept->id, $duplicate->id);
                if ($result !== []) {
                    $moved[$reference['table'].'.'.$reference['column']] = $result;
                }
            }
            foreach ($this->references->handlers() as $name => $handler) {
                $result = $handler($kept->id, $duplicate->id);
                if ($result !== []) {
                    $moved[$name] = $result;
                }
            }

            $filled = [];
            foreach (self::FILLABLE_FROM_DUPLICATE as $field) {
                if (blank($kept->getAttribute($field)) && filled($duplicate->getAttribute($field))) {
                    $kept->setAttribute($field, $duplicate->getAttribute($field));
                    $filled[] = $field;
                }
            }
            $kept->save();

            $snapshot = $duplicate->getAttributes();
            $duplicate->forceFill(['duplicate_of_person_id' => $kept->id, 'archived_at' => now()])->save();

            foreach ([[$kept, (string) $duplicate->id], [$duplicate, (string) $kept->id]] as [$person, $other]) {
                PersonStatusHistory::query()->create([
                    'person_id' => $person->id, 'kind' => PersonStatusHistory::MERGED,
                    'new_value' => $other, 'changed_by_user_id' => $actor->id,
                ]);
            }

            [$a, $b] = $kept->id < $duplicate->id ? [$kept->id, $duplicate->id] : [$duplicate->id, $kept->id];
            // Merged by hand, without a queued pair: the pair is recorded all the same.
            $pair = DuplicateCandidate::query()->firstOrNew(['person_a_id' => $a, 'person_b_id' => $b], ['reasons' => []]);
            $pair->fill(['status' => DuplicateCandidate::MERGED, 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();
            // Other open pairs of the duplicate are moot now; the kept card is re-scanned by the caller if needed.
            DuplicateCandidate::query()->where('status', DuplicateCandidate::OPEN)
                ->where(fn ($q) => $q->where('person_a_id', $duplicate->id)->orWhere('person_b_id', $duplicate->id))
                ->update(['status' => DuplicateCandidate::DISMISSED, 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);

            $merge = PersonMerge::query()->create([
                'kept_person_id' => $kept->id,
                'merged_person_id' => $duplicate->id,
                'merged_by_user_id' => $actor->id,
                'moved' => [...$moved, 'filled' => $filled],
                'snapshot' => $snapshot,
            ]);
            $this->journal->record('crm.people.merged', $kept, [], [
                'merged_person_id' => $duplicate->id,
                'merge_id' => $merge->id,
                'references' => array_map(fn (array $result): int => count($result['moved'] ?? []), $moved),
            ]);

            return $merge;
        });
    }

    /**
     * @param  array{table: string, column: string, unique: list<string>}  $reference
     * @return array<string, mixed>
     */
    private function repoint(array $reference, int $keptId, int $duplicateId): array
    {
        ['table' => $table, 'column' => $column, 'unique' => $unique] = $reference;
        $rows = DB::table($table)->where($column, $duplicateId)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $result = ['moved' => [], 'redundant' => []];
        foreach ($rows as $row) {
            $row = (array) $row;
            $collides = $unique !== [] && DB::table($table)->where($column, $keptId)
                ->where(function ($q) use ($unique, $row): void {
                    foreach ($unique as $other) {
                        $q->where($other, $row[$other]);
                    }
                })->exists();
            // A relation of the duplicate with the kept card itself would become a relation with oneself.
            $selfReference = false;
            foreach ($this->peopleColumns($table, $unique) as $other) {
                $selfReference = $selfReference || (int) $row[$other] === $keptId;
            }

            $where = isset($row['id']) ? ['id' => $row['id']] : array_intersect_key($row, array_flip([$column, ...$unique]));
            if ($collides || $selfReference) {
                $result['redundant'][] = $row;
                DB::table($table)->where($where)->delete();
            } else {
                DB::table($table)->where($where)->update([$column => $keptId]);
                $result['moved'][] = $row['id'] ?? $where;
            }
        }

        return array_filter($result);
    }

    /**
     * Columns of the unique key that point at people themselves.
     *
     * @param  list<string>  $unique
     * @return list<string>
     */
    private function peopleColumns(string $table, array $unique): array
    {
        $columns = [];
        foreach ($this->references->all() as $reference) {
            if ($reference['table'] === $table && in_array($reference['column'], $unique, true)) {
                $columns[] = $reference['column'];
            }
        }

        return $columns;
    }
}
