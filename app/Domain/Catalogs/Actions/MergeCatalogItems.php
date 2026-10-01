<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Events\CatalogItemsMerged;
use App\Domain\Catalogs\Exceptions\CatalogRuleViolation;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate into the item that stays. The duplicate is deactivated and points to the target;
 * modules move their references on CatalogItemsMerged (same transaction).
 */
final readonly class MergeCatalogItems
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, CatalogItem $duplicate, CatalogItem $target): void
    {
        $this->authorization->authorize($actor, 'catalogs.manage');
        if ($duplicate->catalog_code !== $target->catalog_code || $duplicate->is($target)) {
            throw CatalogRuleViolation::differentCatalogs();
        }
        if ($duplicate->is_system) {
            throw CatalogRuleViolation::systemItem();
        }

        DB::transaction(function () use ($duplicate, $target): void {
            $duplicate->update(['is_active' => false, 'merged_into_id' => $target->id]);
            CatalogItemsMerged::dispatch($duplicate->catalog_code, $duplicate->code, $target->code);
            $this->journal->record('catalogs.item.merged', $duplicate, [], ['into' => $target->code]);
        });
    }
}
