<?php

declare(strict_types=1);

namespace App\Domain\Groups\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to join a closed group.
 *
 * @property int $id
 * @property int $group_id
 * @property int $person_id
 * @property string|null $message
 * @property string $status
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property-read Person $person
 * @property-read Group $group
 */
class GroupJoinRequest extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    protected $attributes = ['status' => self::PENDING];

    protected $fillable = ['group_id', 'person_id', 'message', 'status', 'decided_by_user_id', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
