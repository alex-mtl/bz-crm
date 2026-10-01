<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\CatalogItemWriter;
use App\Domain\Catalogs\Enums\ProposalStatus;
use App\Domain\Catalogs\Exceptions\CatalogRuleViolation;
use App\Domain\Catalogs\Models\CatalogItemProposal;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ReviewCatalogProposal
{
    public function __construct(
        private AuthorizationService $authorization,
        private CatalogItemWriter $writer,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array<string, string|null>  $correctedNames  reviewer may fix names before approving
     */
    public function __invoke(User $actor, CatalogItemProposal $proposal, bool $approve, ?string $comment = null, array $correctedNames = []): CatalogItemProposal
    {
        $this->authorization->authorize($actor, 'catalogs.review');
        if ($proposal->status !== ProposalStatus::Pending) {
            throw CatalogRuleViolation::alreadyReviewed();
        }
        if (! $approve && trim((string) $comment) === '') {
            throw CatalogRuleViolation::rejectionNeedsComment();
        }

        return DB::transaction(function () use ($actor, $proposal, $approve, $comment, $correctedNames): CatalogItemProposal {
            $itemId = null;
            if ($approve) {
                $names = [...$proposal->names, ...array_filter($correctedNames, fn (?string $n): bool => trim((string) $n) !== '')];
                $item = $this->writer->create($proposal->catalog_code, $names, $proposal->source_locale, $proposal->properties ?? []);
                $itemId = $item->id;
                $this->journal->record('catalogs.item.created', $item, [], [
                    ...$item->only(['catalog_code', 'code', 'name_ro', 'name_ru', 'name_en']),
                    'from_proposal' => $proposal->id,
                ]);
            }

            $proposal->update([
                'status' => $approve ? ProposalStatus::Approved : ProposalStatus::Rejected,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_comment' => $comment !== null ? trim($comment) : null,
                'created_item_id' => $itemId,
            ]);
            $this->journal->record($approve ? 'catalogs.proposal.approved' : 'catalogs.proposal.rejected', $proposal, [], [
                'comment' => $proposal->review_comment,
                'created_item_id' => $itemId,
            ]);

            return $proposal;
        });
    }
}
