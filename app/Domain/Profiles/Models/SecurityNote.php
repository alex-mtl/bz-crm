<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Support\Retention\HasRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Security service note (ФО §6.3.3): security service and organization head only.
 *
 * @property int $id
 * @property int $person_id
 * @property int $author_user_id
 * @property string $body
 * @property Carbon $created_at
 * @property CarbonImmutable|null $retain_until
 */
class SecurityNote extends Model
{
    use HasRetention;

    protected $fillable = ['person_id', 'author_user_id', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
