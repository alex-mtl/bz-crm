<?php

declare(strict_types=1);

namespace App\Domain\CRM\Events;

use App\Domain\CRM\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event of every move of a lead (ТЗ §28, §44): stage changes may trigger automation rules later.
 */
final class LeadStageChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?int $fromStageId,
        public readonly int $toStageId,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $actorUserId,
    ) {}
}
