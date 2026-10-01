<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Models;

use App\Domain\Catalogs\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $catalog_code
 * @property array<string, string> $names
 * @property string $source_locale
 * @property string $justification
 * @property array<string, mixed>|null $properties
 * @property list<int>|null $similar_item_ids
 * @property ProposalStatus $status
 * @property int $proposed_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_comment
 * @property int|null $created_item_id
 */
class CatalogItemProposal extends Model
{
    protected $fillable = [
        'catalog_code', 'names', 'source_locale', 'justification', 'properties', 'similar_item_ids',
        'status', 'proposed_by_user_id', 'reviewed_by_user_id', 'reviewed_at', 'review_comment', 'created_item_id',
    ];

    protected function casts(): array
    {
        return [
            'names' => 'array',
            'properties' => 'array',
            'similar_item_ids' => 'array',
            'status' => ProposalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
