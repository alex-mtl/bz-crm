<?php

declare(strict_types=1);

namespace App\Domain\CRM\Events;

use App\Domain\CRM\Models\Appeal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event of every status change of an appeal (ТЗ §44).
 */
final class AppealStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Appeal $appeal,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?int $actorUserId,
    ) {}
}
