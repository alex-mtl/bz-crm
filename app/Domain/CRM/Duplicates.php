<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\People\PossibleDuplicateFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The queue of possible duplicates (ФО §6.9.1, ТЗ §27), built on the same finder as the "second account" hints
 * (Д-10). It only proposes: merging is a separate, explicit action.
 */
final readonly class Duplicates
{
    public function __construct(
        private PossibleDuplicateFinder $finder,
        private AuthorizationService $authorization,
        private EventJournal $journal,
    ) {}

    /**
     * Looks for cards matching this one and queues the new pairs. A dismissed pair is never raised again.
     *
     * @return int number of new pairs
     */
    public function scan(Person $person): int
    {
        if ($person->isArchived() || $person->duplicate_of_person_id !== null) {
            return 0;
        }

        $created = 0;
        foreach ($this->finder->find($person) as $otherId => $reasons) {
            [$a, $b] = $person->id < $otherId ? [$person->id, $otherId] : [$otherId, $person->id];
            $candidate = DuplicateCandidate::query()->firstOrNew(['person_a_id' => $a, 'person_b_id' => $b]);
            if ($candidate->exists) {
                if ($candidate->status === DuplicateCandidate::OPEN && $candidate->reasons !== $reasons) {
                    $candidate->update(['reasons' => $reasons]);
                }

                continue;
            }
            $candidate->fill(['reasons' => $reasons, 'status' => DuplicateCandidate::OPEN])->save();
            $created++;
        }

        return $created;
    }

    public function scanAll(): int
    {
        $created = 0;
        Person::query()->whereNull('archived_at')->whereNull('duplicate_of_person_id')->orderBy('id')
            ->chunkById(500, function ($people) use (&$created): void {
                foreach ($people as $person) {
                    $created += $this->scan($person);
                }
            });

        return $created;
    }

    /**
     * Open pairs the reviewer may see: both cards must be within their scope (catalog §5).
     *
     * @return Builder<DuplicateCandidate>
     */
    public function queueFor(User $reviewer): Builder
    {
        $visible = fn () => $this->authorization->scopeQuery($reviewer, 'crm.duplicates.review', Person::query())->select('people.id');

        return DuplicateCandidate::query()
            ->whereIn('person_a_id', $visible())
            ->whereIn('person_b_id', $visible());
    }

    public function mayReview(User $reviewer, DuplicateCandidate $candidate): bool
    {
        return $this->authorization->can($reviewer, 'crm.duplicates.review', $candidate->personA)
            && $this->authorization->can($reviewer, 'crm.duplicates.review', $candidate->personB);
    }

    public function dismiss(User $actor, DuplicateCandidate $candidate): void
    {
        $this->authorization->authorize($actor, 'crm.duplicates.review', $candidate->personA);
        $this->authorization->authorize($actor, 'crm.duplicates.review', $candidate->personB);
        if ($candidate->status !== DuplicateCandidate::OPEN) {
            throw CrmRuleViolation::because('duplicate_not_open');
        }

        DB::transaction(function () use ($actor, $candidate): void {
            $candidate->update(['status' => DuplicateCandidate::DISMISSED, 'decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $this->journal->record('crm.duplicate.dismissed', $candidate->personA, [], ['other_person_id' => $candidate->person_b_id]);
        });
    }
}
