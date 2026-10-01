<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Identity\Models\User;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string $person_type
 * @property string $preferred_locale
 * @property int|null $duplicate_of_person_id
 * @property Carbon|null $archived_at
 * @property int|null $territory_id
 * @property int|null $responsible_unit_id
 * @property string|null $source_code
 * @property int|null $import_batch_id
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    protected $table = 'people';

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'person_type', 'preferred_locale', 'duplicate_of_person_id', 'archived_at',
        'territory_id', 'responsible_unit_id', 'source_code', 'import_batch_id',
    ];

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }
}
