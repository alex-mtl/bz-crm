<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved filter over the people registry (ФО §6.9.4). Personal — only for its owner; shared — a segment of the
 * organization. Who is in it is computed on reading, within the scope of the reader.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $criteria
 * @property string $visibility
 * @property int $owner_user_id
 * @property-read User $owner
 */
class Segment extends Model
{
    public const string PERSONAL = 'personal';

    public const string SHARED = 'shared';

    protected $fillable = ['name', 'description', 'criteria', 'visibility', 'owner_user_id'];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
