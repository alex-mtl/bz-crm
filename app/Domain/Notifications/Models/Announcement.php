<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An announcement sent to an area (ФО §6.13 "системные объявления"). A critical one waits for every recipient
 * to confirm that they have read it.
 *
 * @property int $id
 * @property int $sender_user_id
 * @property string $title
 * @property string $body
 * @property bool $is_critical
 * @property array<string, mixed>|null $audience
 * @property int $recipients
 * @property Carbon $created_at
 * @property-read User $sender
 */
class Announcement extends Model
{
    protected $fillable = ['sender_user_id', 'title', 'body', 'is_critical', 'audience', 'recipients'];

    protected $attributes = ['is_critical' => false, 'recipients' => 0];

    protected function casts(): array
    {
        return ['is_critical' => 'boolean', 'audience' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return HasMany<AnnouncementReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }
}
