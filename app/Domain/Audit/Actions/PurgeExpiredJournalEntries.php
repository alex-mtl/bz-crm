<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\RetentionPolicy;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way to remove journal rows: retention expiry. The purge itself is journaled.
 */
final readonly class PurgeExpiredJournalEntries
{
    public function __construct(private RetentionPolicy $policy, private EventJournal $journal) {}

    /**
     * @return array<string, int> deleted rows per category
     */
    public function __invoke(): array
    {
        $deleted = [];

        foreach (EventCategory::cases() as $category) {
            $days = $this->policy->daysFor($category);
            if ($days === null) {
                continue;
            }

            $count = DB::table('journal_entries')
                ->where('category', $category->value)
                ->where('occurred_at', '<', now()->subDays($days))
                ->delete();

            if ($count > 0) {
                $deleted[$category->value] = $count;
            }
        }

        if ($deleted !== []) {
            $this->journal->record('audit.retention.purged', null, [], ['deleted' => $deleted]);
        }

        return $deleted;
    }
}
