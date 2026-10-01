<?php

declare(strict_types=1);

namespace App\Domain\Groups\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $group_id
 * @property int $person_id
 * @property string $role
 * @property Carbon|null $joined_at
 * @property-read Person $person
 * @property-read Group $group
 */
class GroupMember extends Model
{
    public const string OWNER = 'owner';

    public const string ADMIN = 'admin';

    public const string MODERATOR = 'moderator';

    public const string MEMBER = 'member';

    public const array ROLES = [self::OWNER, self::ADMIN, self::MODERATOR, self::MEMBER];

    /** Who manages the group: members, requests, roles, rules. */
    public const array MANAGERS = [self::OWNER, self::ADMIN];

    /** Who moderates the feed of the group. */
    public const array MODERATORS = [self::OWNER, self::ADMIN, self::MODERATOR];

    protected $fillable = ['group_id', 'person_id', 'role', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
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
