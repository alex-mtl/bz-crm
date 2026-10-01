<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Support\Translation\HasTranslatedName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable engagement pipeline (ФО §6.9.2): "Joining the organization", "Event volunteers".
 *
 * @property int $id
 * @property string $code
 * @property string $name_ro
 * @property string $name_ru
 * @property string $name_en
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property list<string>|null $auto_enroll_types
 * @property bool $auto_assign_by_territory
 */
class Pipeline extends Model
{
    use HasTranslatedName;

    protected $fillable = ['code', 'name_ro', 'name_ru', 'name_en', 'description', 'is_active', 'sort_order', 'auto_enroll_types', 'auto_assign_by_territory'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'auto_enroll_types' => 'array', 'auto_assign_by_territory' => 'boolean'];
    }

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function firstStage(): ?PipelineStage
    {
        return $this->stages()->where('is_active', true)->where('kind', PipelineStage::OPEN)->first();
    }
}
