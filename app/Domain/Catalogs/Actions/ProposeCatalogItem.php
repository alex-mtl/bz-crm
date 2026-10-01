<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\CatalogItemWriter;
use App\Domain\Catalogs\CatalogRegistry;
use App\Domain\Catalogs\Enums\ProposalStatus;
use App\Domain\Catalogs\Models\CatalogItemProposal;
use App\Domain\Catalogs\SimilarItemFinder;
use App\Domain\Identity\Models\User;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Д-16: managers propose, the catalog administrator decides. Similar existing items are attached
 * to the proposal so the reviewer (and the author) see possible duplicates.
 */
final readonly class ProposeCatalogItem
{
    public function __construct(
        private AuthorizationService $authorization,
        private CatalogRegistry $catalogs,
        private CatalogItemWriter $writer,
        private SimilarItemFinder $similar,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array<string, string|null>  $names
     * @param  array<string, mixed>  $properties
     */
    public function __invoke(User $actor, string $catalogCode, array $names, string $sourceLocale, string $justification, array $properties = []): CatalogItemProposal
    {
        $this->authorization->authorize($actor, 'catalogs.propose');
        $definition = $this->catalogs->get($catalogCode);
        TranslatedNames::complete($names, $sourceLocale); // validates the source-language name
        if (trim($justification) === '') {
            throw new InvalidArgumentException(__('catalogs.errors.justification_required'));
        }

        return DB::transaction(function () use ($actor, $definition, $names, $sourceLocale, $justification, $properties): CatalogItemProposal {
            $similarIds = $this->similar->find($definition->code, $names)->pluck('id')->all();

            $proposal = CatalogItemProposal::query()->create([
                'catalog_code' => $definition->code,
                'names' => array_filter($names, fn (?string $n): bool => trim((string) $n) !== ''),
                'source_locale' => $sourceLocale,
                'justification' => trim($justification),
                'properties' => $this->writer->validProperties($definition, $properties),
                'similar_item_ids' => $similarIds,
                'status' => ProposalStatus::Pending,
                'proposed_by_user_id' => $actor->id,
            ]);
            $this->journal->record('catalogs.proposal.submitted', $proposal, [], [
                'catalog' => $definition->code,
                'names' => $proposal->names,
                'similar_item_ids' => $similarIds,
            ]);

            return $proposal;
        });
    }
}
