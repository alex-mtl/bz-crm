<?php

declare(strict_types=1);

namespace App\Domain\Social;

/**
 * Catalogs owned by the social network (Д-16).
 */
final class SocialCatalogs
{
    /**
     * @return list<array{code: string, properties?: array<string, 'bool'|'int'|'string'>, data?: string}>
     */
    public static function definitions(): array
    {
        return [
            // ФО §6.4.3: a configurable set of reactions; "symbol" is what the button shows.
            ['code' => 'reaction_types', 'properties' => ['symbol' => 'string'], 'data' => 'reaction-types.csv'],
            ['code' => 'report_reasons', 'data' => 'report-reasons.csv'],
        ];
    }
}
