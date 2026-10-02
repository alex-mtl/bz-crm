<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Files\Exceptions\FileRejected;

/**
 * The check a file passes before a module stores it. With the protection turned off (Д-28) every file is let
 * through unchecked. With it on, an infected file is refused, and so is any file while the scanner does not
 * answer — better to ask the person to try again than to accept a file unchecked.
 */
final readonly class FileGate
{
    public function __construct(
        private AttachmentScanner $scanner,
        private AntivirusProtection $protection,
    ) {}

    public function scan(string $absolutePath): ScanVerdict
    {
        return $this->protection->enabled() ? $this->scanner->scan($absolutePath) : ScanVerdict::Skipped;
    }

    public function ensureAcceptable(string $absolutePath, string $name): ScanVerdict
    {
        $verdict = $this->scan($absolutePath);
        if (! $verdict->lets()) {
            throw FileRejected::because($verdict, $name);
        }

        return $verdict;
    }
}
