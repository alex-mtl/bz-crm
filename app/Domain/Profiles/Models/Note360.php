<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Domain\Profiles\Enums\Note360Type;
use App\Support\Retention\HasRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A "360" note (Д-14). The subject never sees it; the type is fixed at creation.
 *
 * @property int $id
 * @property int $subject_person_id
 * @property int $author_user_id
 * @property int $author_person_id
 * @property Note360Type $type
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property CarbonImmutable|null $retain_until
 */
class Note360 extends Model
{
    use HasRetention;

    protected $table = 'notes_360';

    protected $fillable = ['subject_person_id', 'author_user_id', 'author_person_id', 'type', 'body'];

    protected function casts(): array
    {
        return ['type' => Note360Type::class, 'body' => 'encrypted'];
    }
}
