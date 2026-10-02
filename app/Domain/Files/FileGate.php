<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Files\Exceptions\FileRejected;

/**
 * The check a file passes before a module stores it: an infected file is refused, and so is any file while a
 * configured scanner does not answer — better to ask the person to try again than to accept a file unchecked.
 */
final readonly class FileGate
{
    public function __construct(private AttachmentScanner $scanner) {}

    public function ensureAcceptable(string $absolutePath, string $name): ScanVerdict
    {
        $verdict = $this->scanner->scan($absolutePath);
        if (! $verdict->lets()) {
            throw FileRejected::because($verdict, $name);
        }

        return $verdict;
    }
}
