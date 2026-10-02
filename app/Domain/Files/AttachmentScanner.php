<?php

declare(strict_types=1);

namespace App\Domain\Files;

/**
 * Port to the antivirus (ФО §6.6.4 "на уровне инфраструктуры", ADR-012). Modules do not ask it directly: they go
 * through FileGate, which first looks at the switch of the super admin (Д-28).
 */
interface AttachmentScanner
{
    public function scan(string $absolutePath): ScanVerdict;

    /** Does the service answer at all — asked before the protection is turned on, and by the status page. */
    public function reachable(): bool;
}
