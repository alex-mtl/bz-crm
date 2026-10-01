<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Rejected = 'rejected';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return __('identity.statuses.'.$this->value);
    }
}
