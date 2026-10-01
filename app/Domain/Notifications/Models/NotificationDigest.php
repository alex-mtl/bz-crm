<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A digest built for a person and a period. The unique key (user, frequency, period) keeps a repeated run from sending it twice.
 *
 * @property int $id
 * @property int $user_id
 * @property string $frequency
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<int, array<string, mixed>> $summary
 */
class NotificationDigest extends Model
{
    protected $table = 'notification_digests';

    protected $fillable = ['user_id', 'frequency', 'period_start', 'period_end', 'summary'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'summary' => 'array'];
    }
}
