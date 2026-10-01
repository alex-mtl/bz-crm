<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A calendar subscription (ФО §6.7 "iCal feed"): a secret address an external calendar reads. Only the hash of
 * the token is stored; the feed always shows what its owner may see at the moment of reading.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $scope
 * @property int|null $scope_id
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class CalendarFeed extends Model
{
    public const string PERSONAL = 'personal';

    public const string TERRITORY = 'territory';

    public const string GROUP = 'group';

    public const array SCOPES = [self::PERSONAL, self::TERRITORY, self::GROUP];

    protected $fillable = ['user_id', 'token_hash', 'scope', 'scope_id', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
