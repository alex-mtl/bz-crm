<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The record of a merge: what was re-pointed and what the merged card looked like — history is never lost (ТЗ §27).
 *
 * @property int $id
 * @property int $kept_person_id
 * @property int $merged_person_id
 * @property int|null $merged_by_user_id
 * @property array<string, mixed> $moved
 * @property array<string, mixed> $snapshot
 * @property Carbon $created_at
 */
class PersonMerge extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['kept_person_id', 'merged_person_id', 'merged_by_user_id', 'moved', 'snapshot'];

    protected function casts(): array
    {
        return ['moved' => 'array', 'snapshot' => 'array'];
    }
}
