<?php

declare(strict_types=1);

namespace App\Domain\People\Enums;

enum LinkHintStatus: string
{
    case Open = 'open';
    case Linked = 'linked';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return __('people.link_hint_statuses.'.$this->value);
    }
}
