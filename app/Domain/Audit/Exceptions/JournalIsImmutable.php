<?php

declare(strict_types=1);

namespace App\Domain\Audit\Exceptions;

use LogicException;

final class JournalIsImmutable extends LogicException
{
    public static function make(): self
    {
        return new self('Journal entries are append-only and cannot be changed or deleted.');
    }
}
