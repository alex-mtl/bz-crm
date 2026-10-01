<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use DomainException;

final class OrganizationRuleViolation extends DomainException
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('organization.errors.'.$key, $replace));
    }
}
