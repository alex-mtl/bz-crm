<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Exceptions;

use DomainException;

final class MessagingRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('messaging.errors.'.$key, $replace));
    }
}
