<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum ActorType: string
{
    case User = 'user';
    case Guest = 'guest';
    case System = 'system';
    case Job = 'job';
    case Automation = 'automation';
    case Ai = 'ai';

    public function label(): string
    {
        return __('journal.actor_types.'.$this->value);
    }
}
