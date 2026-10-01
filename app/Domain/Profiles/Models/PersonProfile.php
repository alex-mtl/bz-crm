<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Domain\Profiles\Enums\FieldVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The open layer, filled by the owner (ФО §6.3.1).
 *
 * @property int $person_id
 * @property string|null $photo_path
 * @property string|null $cover_code
 * @property string|null $bio
 * @property Carbon|null $birth_date
 * @property string|null $gender
 * @property FieldVisibility $personal_visibility
 * @property list<string>|null $skills
 * @property list<string>|null $interests
 * @property list<string>|null $languages
 * @property FieldVisibility $skills_visibility
 */
class PersonProfile extends Model
{
    protected $primaryKey = 'person_id';

    public $incrementing = false;

    protected $fillable = [
        'person_id', 'photo_path', 'cover_code', 'bio', 'birth_date', 'gender', 'personal_visibility',
        'skills', 'interests', 'languages', 'skills_visibility',
    ];

    protected $attributes = ['personal_visibility' => 'management', 'skills_visibility' => 'all'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'personal_visibility' => FieldVisibility::class,
            'skills_visibility' => FieldVisibility::class,
            'skills' => 'array',
            'interests' => 'array',
            'languages' => 'array',
        ];
    }
}
