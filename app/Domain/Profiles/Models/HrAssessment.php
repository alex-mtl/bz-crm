<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Support\Retention\HasRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR assessment (ФО §6.3.3): HR, organization head, direct manager. Never the owner.
 *
 * @property int $id
 * @property int $person_id
 * @property int $author_user_id
 * @property int|null $rating
 * @property string|null $potential
 * @property string|null $strengths
 * @property string|null $development
 * @property string|null $recommendations
 * @property Carbon $created_at
 * @property CarbonImmutable|null $retain_until
 */
class HrAssessment extends Model
{
    use HasRetention;

    protected $fillable = ['person_id', 'author_user_id', 'rating', 'potential', 'strengths', 'development', 'recommendations'];

    protected function casts(): array
    {
        return ['strengths' => 'encrypted', 'development' => 'encrypted', 'recommendations' => 'encrypted'];
    }
}
