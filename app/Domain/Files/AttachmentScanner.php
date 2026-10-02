<?php

declare(strict_types=1);

namespace App\Domain\Files;

/**
 * Port to the antivirus (ФО §6.6.4 "на уровне инфраструктуры", ADR-012). Modules that accept files ask it;
 * which engine answers — ClamAV or nothing at all — is a matter of configuration, not of the modules.
 */
interface AttachmentScanner
{
    public function scan(string $absolutePath): ScanVerdict;
}
