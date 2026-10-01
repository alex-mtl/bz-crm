<?php

declare(strict_types=1);

namespace App\Domain\Projects\Exceptions;

use DomainException;

final class ProjectRuleViolation extends DomainException
{
    public static function because(string $key): self
    {
        return new self(__('projects.errors.'.$key));
    }
}
