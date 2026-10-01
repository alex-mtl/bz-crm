<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Enums;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('catalogs.proposal_statuses.'.$this->value);
    }
}
