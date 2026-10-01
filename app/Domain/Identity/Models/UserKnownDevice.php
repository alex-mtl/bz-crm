<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $fingerprint
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
class UserKnownDevice extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'fingerprint', 'ip_address', 'user_agent', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
