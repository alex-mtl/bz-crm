<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The default of a role for a category and a channel (ФО §6.13 "с умолчаниями по ролям").
 *
 * @property int $id
 * @property int $role_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 */
class NotificationDefault extends Model
{
    protected $table = 'notification_defaults';

    protected $fillable = ['role_id', 'category', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
