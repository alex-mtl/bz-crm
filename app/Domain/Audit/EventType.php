<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;

final readonly class EventType
{
    /**
     * @param  list<string>  $maskedFields  keys whose values are replaced before storing
     */
    public function __construct(
        public string $code,
        public EventCategory $category,
        public EventSeverity $severity = EventSeverity::Info,
        public array $maskedFields = [],
    ) {}

    public function label(): string
    {
        return __($this->labelKey());
    }

    public function labelKey(): string
    {
        return 'journal.events.'.$this->code;
    }
}
