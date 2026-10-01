<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use InvalidArgumentException;
use LogicException;

final class CatalogRegistry
{
    /** @var array<string, CatalogDefinition> */
    private array $definitions = [];

    public function register(CatalogDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->code])) {
                throw new LogicException("Catalog [{$definition->code}] is already registered.");
            }
            $this->definitions[$definition->code] = $definition;
        }
    }

    public function get(string $code): CatalogDefinition
    {
        return $this->definitions[$code] ?? throw new InvalidArgumentException("Unknown catalog [{$code}].");
    }

    public function has(string $code): bool
    {
        return isset($this->definitions[$code]);
    }

    /**
     * @return array<string, CatalogDefinition>
     */
    public function all(): array
    {
        ksort($this->definitions);

        return $this->definitions;
    }
}
