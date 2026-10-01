<?php

declare(strict_types=1);

namespace App\Domain\People;

use Closure;

/**
 * Where other modules point at a person. Each module registers its columns, so merging two cards (ТЗ §27)
 * re-points every reference without the People module knowing those modules.
 */
final class PersonReferences
{
    /** @var array<string, array{table: string, column: string, unique: list<string>}> */
    private array $references = [];

    /** @var array<string, Closure(int, int): array<string, mixed>> */
    private array $handlers = [];

    /**
     * @param  list<string>  $uniqueWith  other columns of a unique key the person column is part of: rows that would
     *                                    collide after re-pointing are redundant and are recorded, then removed
     */
    public function register(string $table, string $column, array $uniqueWith = []): void
    {
        $this->references[$table.'.'.$column] = ['table' => $table, 'column' => $column, 'unique' => $uniqueWith];
    }

    /**
     * For data that cannot simply be re-pointed (one row per person, values keyed by something else).
     *
     * @param  Closure(int, int): array<string, mixed>  $handler  (kept person id, merged person id) → what it did
     */
    public function registerHandler(string $name, Closure $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    /**
     * @return list<array{table: string, column: string, unique: list<string>}>
     */
    public function all(): array
    {
        return array_values($this->references);
    }

    /**
     * @return array<string, Closure(int, int): array<string, mixed>>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }
}
