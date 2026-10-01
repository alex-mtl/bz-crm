<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One touch with a person (ФО §6.9.1): a call, a meeting, a message, a visit. Together they form the feed of the card.
 *
 * @property int $id
 * @property int $person_id
 * @property string $kind_code
 * @property string|null $direction
 * @property Carbon $occurred_at
 * @property string|null $summary
 * @property int|null $duration_minutes
 * @property int|null $author_person_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property-read Person $person
 * @property-read Person|null $author
 */
class Interaction extends Model
{
    public const string IN = 'in';

    public const string OUT = 'out';

    protected $fillable = ['person_id', 'kind_code', 'direction', 'occurred_at', 'summary', 'duration_minutes', 'author_person_id', 'subject_type', 'subject_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }
}
