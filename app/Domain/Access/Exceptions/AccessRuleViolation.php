<?php

declare(strict_types=1);

namespace App\Domain\Access\Exceptions;

use DomainException;

final class AccessRuleViolation extends DomainException
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('access.errors.'.$key, $replace));
    }
}
