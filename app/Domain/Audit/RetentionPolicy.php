<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\EventCategory;
use App\Support\Settings\SystemSettings;

/**
 * Retention in days per event category. Null means "keep forever" — the default
 * until the customer fixes concrete periods (open question №13).
 */
final readonly class RetentionPolicy
{
    public const string SETTINGS_KEY = 'audit.retention_days';

    public function __construct(private SystemSettings $settings) {}

    public function daysFor(EventCategory $category): ?int
    {
        $value = $this->settings->get(self::SETTINGS_KEY, [])[$category->value] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return array<string, int|null>
     */
    public function all(): array
    {
        $result = [];
        foreach (EventCategory::cases() as $category) {
            $result[$category->value] = $this->daysFor($category);
        }

        return $result;
    }
}
