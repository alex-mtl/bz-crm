<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Modules that store catalog codes listen to this and re-point their records from source to target.
 */
final readonly class CatalogItemsMerged
{
    use Dispatchable;

    public function __construct(public string $catalogCode, public string $sourceCode, public string $targetCode) {}
}
