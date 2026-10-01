<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Support\Translation\HasTranslatedName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stage of a pipeline. Its kind tells what reaching it means: still in work, won (the goal), lost.
 *
 * @property int $id
 * @property int $pipeline_id
 * @property string $code
 * @property string $name_ro
 * @property string $name_ru
 * @property string $name_en
 * @property string $kind
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $sla_hours
 * @property-read Pipeline $pipeline
 */
class PipelineStage extends Model
{
    use HasTranslatedName;

    public const string OPEN = 'open';

    public const string WON = 'won';

    public const string LOST = 'lost';

    protected $fillable = ['pipeline_id', 'code', 'name_ro', 'name_ru', 'name_en', 'kind', 'sort_order', 'is_active', 'sla_hours'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
