<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\CatalogItemWriter;
use App\Domain\Catalogs\CatalogRegistry;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCatalogItem
{
    public function __construct(
        private AuthorizationService $authorization,
        private CatalogRegistry $catalogs,
        private CatalogItemWriter $writer,
        private EventJournal $journal,
    ) {}

    /**
     * Editing a language's name marks that translation as verified.
     *
     * @param  array<string, string|null>  $names  only the languages being changed
     * @param  array<string, mixed>|null  $properties
     */
    public function __invoke(User $actor, CatalogItem $item, array $names = [], ?array $properties = null): CatalogItem
    {
        $this->authorization->authorize($actor, 'catalogs.manage');

        return DB::transaction(function () use ($item, $names, $properties): CatalogItem {
            $fields = ['name_ro', 'name_ru', 'name_en', 'properties', 'unverified_locales'];
            $old = $item->only($fields);

            foreach (TranslatedNames::locales() as $locale) {
                $value = trim((string) ($names[$locale] ?? ''));
                if ($value !== '') {
                    $item->setAttribute('name_'.$locale, $value);
                }
            }
            $item->unverified_locales = TranslatedNames::unverifiedAfterEdit($item->unverified_locales ?? [], $names);
            if ($properties !== null) {
                $item->properties = $this->writer->validProperties($this->catalogs->get($item->catalog_code), $properties);
            }
            $item->save();

            $this->journal->record('catalogs.item.updated', $item, $old, $item->only($fields));

            return $item;
        });
    }
}
