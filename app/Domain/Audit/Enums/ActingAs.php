<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum ActingAs: string
{
    case Own = 'own';
    case Delegation = 'delegation';
    case Impersonation = 'impersonation';

    public function label(): string
    {
        return __('journal.acting_as.'.$this->value);
    }
}
