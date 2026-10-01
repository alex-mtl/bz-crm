<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use App\Domain\People\PossibleDuplicateFinder;

/**
 * Called inside the registration transaction. Creates "possibly the same person" hints (Д-10).
 */
final readonly class RecordLinkHints
{
    public function __construct(private PossibleDuplicateFinder $finder, private EventJournal $journal) {}

    public function __invoke(Person $newPerson): int
    {
        $count = 0;
        foreach ($this->finder->find($newPerson) as $existingId => $reasons) {
            $hint = AccountLinkHint::query()->firstOrCreate(
                ['new_person_id' => $newPerson->id, 'existing_person_id' => $existingId],
                ['reasons' => $reasons, 'status' => LinkHintStatus::Open],
            );
            if ($hint->wasRecentlyCreated) {
                $this->journal->record('people.link_hint.created', $hint, [], ['reasons' => $reasons]);
                $count++;
            }
        }

        return $count;
    }
}
