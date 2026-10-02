<?php

declare(strict_types=1);

namespace App\Infrastructure\Antivirus;

use App\Domain\Files\AttachmentScanner;
use App\Domain\Files\ScanVerdict;

/**
 * No antivirus in this environment (ATTACHMENT_SCANNER=none): every file is let through and marked as unchecked.
 */
final class NullScanner implements AttachmentScanner
{
    public function scan(string $absolutePath): ScanVerdict
    {
        return ScanVerdict::Skipped;
    }
}
