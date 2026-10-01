<?php

declare(strict_types=1);

namespace App\Domain\Groups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group (ФО §6.5): open — seen by all, free to join; closed — seen by all, joined by request or invitation;
 * secret — invisible to everyone but its members, joined by invitation only.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $rules
 * @property string|null $cover_code
 * @property string $type
 * @property int|null $org_unit_id
 * @property int|null $territory_id
 * @property int|null $project_id
 * @property int|null $created_by_person_id
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 */
class Group extends Model
{
    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    public const string SECRET = 'secret';

    public const array TYPES = [self::OPEN, self::CLOSED, self::SECRET];

    protected $fillable = ['name', 'description', 'rules', 'cover_code', 'type', 'org_unit_id', 'territory_id', 'project_id', 'created_by_person_id', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * @return HasMany<GroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function isSecret(): bool
    {
        return $this->type === self::SECRET;
    }
}
