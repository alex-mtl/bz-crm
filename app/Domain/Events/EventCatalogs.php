<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Catalogs owned by events (Д-16).
 */
final class EventCatalogs
{
    /**
     * @return list<array{code: string, properties?: array<string, 'bool'|'int'|'string'>, data?: string}>
     */
    public static function definitions(): array
    {
        return [
            // ФО §6.7: "тип (встреча, обучение, агитационный выход, собрание штаба — справочник настраивается)".
            ['code' => 'event_types', 'data' => 'event-types.csv'],
        ];
    }
}
