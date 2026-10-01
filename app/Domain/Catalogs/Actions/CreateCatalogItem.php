<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\CatalogItemWriter;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateCatalogItem
{
    public function __construct(
        private AuthorizationService $authorization,
        private CatalogItemWriter $writer,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array<string, string|null>  $names  missing languages are auto-copied and flagged (Д-16)
     * @param  array<string, mixed>  $properties
     */
    public function __invoke(User $actor, string $catalogCode, array $names, string $sourceLocale, array $properties = [], ?string $code = null): CatalogItem
    {
        $this->authorization->authorize($actor, 'catalogs.manage');

        return DB::transaction(function () use ($catalogCode, $names, $sourceLocale, $properties, $code): CatalogItem {
            $item = $this->writer->create($catalogCode, $names, $sourceLocale, $properties, $code);
            $this->journal->record('catalogs.item.created', $item, [], $item->only(['catalog_code', 'code', 'name_ro', 'name_ru', 'name_en', 'properties']));

            return $item;
        });
    }
}
