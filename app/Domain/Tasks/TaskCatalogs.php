<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Catalogs owned by the Tasks module (Д-16). The module itself arrives in phase 2;
 * its reference data is loaded from phase 1 on.
 */
final class TaskCatalogs
{
    /**
     * @return list<array{code: string, properties?: array<string, 'bool'|'int'|'string'>, data?: string}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'task_types', 'properties' => ['requires_review' => 'bool', 'phase' => 'int'], 'data' => 'task-types.csv'],
            // ФО §6.8.4: each status belongs to a system category (none / todo / in_progress / done, Д-16).
            ['code' => 'task_statuses', 'properties' => ['category' => 'string'], 'data' => 'task-statuses.csv'],
            ['code' => 'task_priorities', 'properties' => ['weight' => 'int'], 'data' => 'task-priorities.csv'],
            // ФО §6.8.5: people, money, transport, materials — plan vs fact.
            ['code' => 'resource_kinds', 'properties' => ['unit' => 'string'], 'data' => 'resource-kinds.csv'],
        ];
    }
}
