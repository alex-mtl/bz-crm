<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum EventCategory: string
{
    case Security = 'security';
    case Access = 'access';
    case Data = 'data';
    case Business = 'business';
    case Admin = 'admin';
    case Integration = 'integration';

    public function label(): string
    {
        return __('journal.categories.'.$this->value);
    }
}
