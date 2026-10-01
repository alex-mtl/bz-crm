<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Штаб районной кампании", "Агитационный выход" — a project in two clicks (ФО §6.8.1).
 * structure: {structure: phases|flat, strict_phases: bool, phases: [{name, offset_days, duration_days,
 *             tasks: [{title, type_code, offset_days}]}], tasks: [...]}
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $structure
 * @property int|null $created_by_user_id
 */
class ProjectTemplate extends Model
{
    protected $fillable = ['name', 'description', 'structure', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['structure' => 'array'];
    }
}
