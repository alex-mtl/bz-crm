<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Exceptions\CatalogRuleViolation;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Items are never deleted (Д-16): a deactivated item disappears from choices but stays on old records.
 */
final readonly class SetCatalogItemActive
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    public function __invoke(User $actor, CatalogItem $item, bool $active): void
    {
        $this->authorization->authorize($actor, 'catalogs.manage');
        if (! $active && $item->is_system) {
            throw CatalogRuleViolation::systemItem();
        }
        if ($item->is_active === $active) {
            return;
        }

        DB::transaction(function () use ($item, $active): void {
            $item->update(['is_active' => $active]);
            $this->journal->record($active ? 'catalogs.item.reactivated' : 'catalogs.item.deactivated', $item);
        });
    }
}
