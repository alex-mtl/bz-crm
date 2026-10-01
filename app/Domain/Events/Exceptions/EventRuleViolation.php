<?php

declare(strict_types=1);

namespace App\Domain\Events\Exceptions;

use DomainException;

final class EventRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('events.errors.'.$key, $replace));
    }
}
