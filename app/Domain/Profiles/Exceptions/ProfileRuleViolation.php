<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Exceptions;

use DomainException;

final class ProfileRuleViolation extends DomainException
{
    public static function because(string $key): self
    {
        return new self(__('profiles.errors.'.$key));
    }
}
