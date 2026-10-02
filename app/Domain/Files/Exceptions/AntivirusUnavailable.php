<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

use DomainException;

final class AntivirusUnavailable extends DomainException
{
    public static function make(): self
    {
        return new self(__('files.errors.antivirus_unreachable'));
    }
}
