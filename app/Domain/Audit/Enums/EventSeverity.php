<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum EventSeverity: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return __('journal.severities.'.$this->value);
    }
}
