<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $person_id
 * @property string $role manager | member | observer
 * @property-read Person $person
 */
class ProjectMember extends Model
{
    public const array ROLES = ['manager', 'member', 'observer'];

    protected $fillable = ['project_id', 'person_id', 'role'];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
