<?php

declare(strict_types=1);

namespace App\Domain\People\Exceptions;

use DomainException;

final class PeopleRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('people.errors.'.$key, $replace));
    }
}
