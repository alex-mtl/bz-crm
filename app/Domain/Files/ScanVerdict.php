<?php

declare(strict_types=1);

namespace App\Domain\Files;

/**
 * What the antivirus said about a file (ФО §6.6.4).
 */
enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    /** No scanner is configured in this environment: files are accepted unchecked. */
    case Skipped = 'skipped';
    /** A scanner is configured but did not answer: the file must wait. */
    case Unavailable = 'unavailable';

    public function lets(): bool
    {
        return $this === self::Clean || $this === self::Skipped;
    }
}
