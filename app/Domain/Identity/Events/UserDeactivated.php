<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after an account is deactivated (ФО §6.1). Other modules react — e.g. tasks suggest reassignment (Д-15).
 */
final class UserDeactivated
{
    use Dispatchable;

    public function __construct(public readonly User $user, public readonly int $actorUserId) {}
}
