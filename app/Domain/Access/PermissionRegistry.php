<?php

declare(strict_types=1);

namespace App\Domain\Access;

use LogicException;

/**
 * The permission catalog (Д-17). Each module registers its own codes; nothing else can grant them.
 */
final class PermissionRegistry
{
    /** @var array<string, PermissionDefinition> */
    private array $definitions = [];

    public function register(PermissionDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->code])) {
                throw new LogicException("Permission [{$definition->code}] is already registered.");
            }
            $this->definitions[$definition->code] = $definition;
        }
    }

    public function has(string $code): bool
    {
        return isset($this->definitions[$code]);
    }

    public function get(string $code): PermissionDefinition
    {
        return $this->definitions[$code] ?? throw new LogicException("Permission [{$code}] is not registered.");
    }

    public function isReserved(string $code): bool
    {
        return $this->has($code) && $this->definitions[$code]->reserved;
    }

    /**
     * @return array<string, PermissionDefinition>
     */
    public function all(): array
    {
        ksort($this->definitions);

        return $this->definitions;
    }
}
