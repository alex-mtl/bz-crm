<?php

declare(strict_types=1);

namespace App\Domain\Groups\Exceptions;

use DomainException;

final class GroupRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('groups.errors.'.$key, $replace));
    }
}
