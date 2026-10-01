<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum ApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Linked = 'linked';

    public function label(): string
    {
        return __('identity.application_statuses.'.$this->value);
    }
}
