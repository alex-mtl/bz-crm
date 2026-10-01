<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\People\Enums\LinkHintStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Д-10: "this may be the same person" — never acted on automatically.
 *
 * @property int $id
 * @property int $new_person_id
 * @property int $existing_person_id
 * @property list<string> $reasons
 * @property LinkHintStatus $status
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property-read Person $newPerson
 * @property-read Person $existingPerson
 */
class AccountLinkHint extends Model
{
    protected $fillable = ['new_person_id', 'existing_person_id', 'reasons', 'status', 'resolved_by_user_id', 'resolved_at'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'status' => LinkHintStatus::class, 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function newPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'new_person_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function existingPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'existing_person_id');
    }
}
