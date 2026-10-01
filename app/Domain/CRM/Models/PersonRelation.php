<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A relation between two people (ФО §6.9.1): "invited", "spouse", "colleague". Stored once; read from the other
 * side through the inverse type of the catalog.
 *
 * @property int $id
 * @property int $person_id
 * @property int $related_person_id
 * @property string $relation_code
 * @property string|null $note
 * @property int|null $created_by_user_id
 * @property-read Person $person
 * @property-read Person $relatedPerson
 */
class PersonRelation extends Model
{
    protected $fillable = ['person_id', 'related_person_id', 'relation_code', 'note', 'created_by_user_id'];

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
    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'related_person_id');
    }
}
