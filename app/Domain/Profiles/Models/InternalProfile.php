<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Support\Retention\HasRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal layer (ФО §6.3.2): security service, organization head and the direct manager only.
 *
 * @property int $person_id
 * @property string|null $home_address
 * @property string|null $personal_phone
 * @property list<array{name?: string, phone?: string, relation?: string}>|null $emergency_contacts
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable|null $retain_until
 */
class InternalProfile extends Model
{
    use HasRetention;

    protected $table = 'profile_internal';

    protected $primaryKey = 'person_id';

    public $incrementing = false;

    protected $fillable = ['person_id', 'home_address', 'personal_phone', 'emergency_contacts', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['emergency_contacts' => 'encrypted:array', 'home_address' => 'encrypted', 'personal_phone' => 'encrypted'];
    }
}
