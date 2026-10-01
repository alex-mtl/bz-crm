<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use DomainException;

final class NotificationRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('notifications.errors.'.$key, $replace));
    }
}
