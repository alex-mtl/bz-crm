<?php

declare(strict_types=1);

namespace App\Domain\CRM\Exceptions;

use DomainException;

final class CrmRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('crm.errors.'.$key, $replace));
    }
}
