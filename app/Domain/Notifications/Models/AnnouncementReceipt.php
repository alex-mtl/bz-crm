<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recipient of an announcement; for a critical one — whether and when they confirmed reading it.
 *
 * @property int $id
 * @property int $announcement_id
 * @property int $user_id
 * @property Carbon|null $acknowledged_at
 * @property-read Announcement $announcement
 * @property-read User $user
 */
class AnnouncementReceipt extends Model
{
    protected $fillable = ['announcement_id', 'user_id', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
