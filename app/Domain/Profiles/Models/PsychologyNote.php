<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Support\Retention\HasRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Psychologist's note (ФО §6.3.3): only its author; others only with the reserved right.
 *
 * @property int $id
 * @property int $person_id
 * @property int $author_user_id
 * @property string $body
 * @property Carbon $created_at
 * @property CarbonImmutable|null $retain_until
 */
class PsychologyNote extends Model
{
    use HasRetention;

    protected $fillable = ['person_id', 'author_user_id', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
