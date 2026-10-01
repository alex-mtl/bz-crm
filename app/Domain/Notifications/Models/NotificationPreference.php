<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person's own choice: this category, this channel — on or off (ФО §6.13).
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    protected $fillable = ['user_id', 'category', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
