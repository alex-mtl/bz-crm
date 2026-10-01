<?php

declare(strict_types=1);

namespace App\Domain\Audit\Exceptions;

use LogicException;

final class UnknownEventType extends LogicException
{
    public static function code(string $code): self
    {
        return new self("Journal event type [{$code}] is not registered in the event type catalog.");
    }

    public static function duplicate(string $code): self
    {
        return new self("Journal event type [{$code}] is already registered.");
    }
}
