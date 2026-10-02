<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

use App\Domain\Files\ScanVerdict;
use DomainException;

final class FileRejected extends DomainException
{
    public static function because(ScanVerdict $verdict, string $name): self
    {
        return new self(__('files.errors.'.$verdict->value, ['name' => $name]));
    }
}
