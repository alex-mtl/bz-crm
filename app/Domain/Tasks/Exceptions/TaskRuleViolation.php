<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Exceptions;

use DomainException;

final class TaskRuleViolation extends DomainException
{
    public function __construct(public readonly string $key, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $key): self
    {
        return new self($key, __('tasks.errors.'.$key));
    }
}
