<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An allowed status change (ФО §6.8.4) with what it requires: nothing, a reason, a comment or a resume date.
 *
 * @property int $id
 * @property string $from_status
 * @property string $to_status
 * @property string $requires none | reason | comment | date
 * @property bool $is_active
 */
class StatusTransition extends Model
{
    protected $table = 'task_status_transitions';

    protected $fillable = ['from_status', 'to_status', 'requires', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
