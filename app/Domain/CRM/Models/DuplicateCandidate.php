<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Two cards that may be the same human (ФО §6.9.1, ТЗ §27). A pair is stored once, the lower id first.
 *
 * @property int $id
 * @property int $person_a_id
 * @property int $person_b_id
 * @property list<string> $reasons
 * @property string $status
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property-read Person $personA
 * @property-read Person $personB
 */
class DuplicateCandidate extends Model
{
    public const string OPEN = 'open';

    public const string DISMISSED = 'dismissed';

    public const string MERGED = 'merged';

    protected $fillable = ['person_a_id', 'person_b_id', 'reasons', 'status', 'decided_by_user_id', 'decided_at'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function personA(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_a_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function personB(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_b_id');
    }
}
