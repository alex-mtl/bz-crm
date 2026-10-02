<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A note of an agitator about a flat (ФО §6.11): personal — for the author only; team — for the staff.
 * The text is encrypted at rest. Who reads which note is decided by FieldNotes only.
 *
 * @property int $id
 * @property int $apartment_id
 * @property int $house_id
 * @property int|null $visit_id
 * @property int $author_person_id
 * @property string $visibility
 * @property string $body
 * @property Carbon $created_at
 * @property-read Person $author
 * @property-read Apartment $apartment
 */
class ApartmentNote extends Model
{
    public const string PERSONAL = 'personal';

    public const string TEAM = 'team';

    public const array VISIBILITIES = [self::PERSONAL, self::TEAM];

    protected $fillable = ['apartment_id', 'house_id', 'visit_id', 'author_person_id', 'visibility', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }

    /**
     * @return BelongsTo<Apartment, $this>
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}
