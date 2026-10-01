<?php

declare(strict_types=1);

namespace App\Domain\CRM;

/**
 * Catalogs owned by the CRM (Д-16).
 */
final class CrmCatalogs
{
    /**
     * @return list<array{code: string, properties?: array<string, 'bool'|'int'|'string'>, data?: string}>
     */
    public static function definitions(): array
    {
        return [
            // ФО §6.9.1: "spouse", "colleague", "invited"; inverse = how the relation reads from the other side.
            ['code' => 'person_relation_types', 'properties' => ['inverse' => 'string'], 'data' => 'person-relation-types.csv'],
            ['code' => 'interaction_kinds', 'data' => 'interaction-kinds.csv'],
            // Where a person, a lead or an appeal came from.
            ['code' => 'contact_sources', 'data' => 'contact-sources.csv'],
            ['code' => 'lead_loss_reasons', 'data' => 'lead-loss-reasons.csv'],
            // due_days: default deadline of an appeal of this type.
            ['code' => 'appeal_types', 'properties' => ['due_days' => 'int'], 'data' => 'appeal-types.csv'],
            // Д-22: the priority sets the deadline of the first response and, when due_days > 0, of the resolution
            // (otherwise the type of the appeal decides).
            ['code' => 'appeal_priorities', 'properties' => ['first_response_hours' => 'int', 'due_days' => 'int', 'weight' => 'int'], 'data' => 'appeal-priorities.csv'],
        ];
    }
}
