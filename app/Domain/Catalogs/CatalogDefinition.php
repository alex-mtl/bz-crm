<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

final readonly class CatalogDefinition
{
    /**
     * @param  array<string, 'bool'|'int'|'string'>  $properties  extra item properties and their types
     * @param  string|null  $dataFile  reference data CSV (relative to database/data/catalogs)
     */
    public function __construct(
        public string $code,
        public string $module,
        public array $properties = [],
        public ?string $dataFile = null,
    ) {}

    public function labelKey(): string
    {
        return 'catalogs.names.'.$this->code;
    }

    public function label(): string
    {
        return __($this->labelKey());
    }
}
