<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\RetentionPolicy;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateRetentionPolicy
{
    public function __construct(
        private SystemSettings $settings,
        private RetentionPolicy $policy,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array<string, int|null>  $daysByCategory  category => days (null = keep forever)
     */
    public function __invoke(array $daysByCategory, ?int $userId = null): void
    {
        $new = [];
        foreach ($daysByCategory as $category => $days) {
            if (EventCategory::tryFrom($category) === null) {
                throw new InvalidArgumentException("Unknown journal category [{$category}].");
            }
            if ($days !== null && $days < 1) {
                throw new InvalidArgumentException('Retention must be a positive number of days or empty.');
            }
            $new[$category] = $days;
        }

        DB::transaction(function () use ($new, $userId): void {
            $old = $this->policy->all();
            $this->settings->put(RetentionPolicy::SETTINGS_KEY, $new, $userId);
            $this->journal->record('audit.settings.updated', null, ['retention_days' => $old], ['retention_days' => $new]);
        });
    }
}
