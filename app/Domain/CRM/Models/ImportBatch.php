<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import file on its way: uploaded → validated (preview, dry run) → importing → completed, or failed,
 * or rolled back (ТЗ §68).
 *
 * @property int $id
 * @property string $kind
 * @property string $original_name
 * @property string $path
 * @property string $format
 * @property string $status
 * @property array<string, mixed>|null $options
 * @property array<string, int>|null $totals
 * @property int $created_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $rolled_back_at
 * @property string|null $failure
 * @property Carbon $created_at
 * @property-read User $creator
 */
class ImportBatch extends Model
{
    public const string UPLOADED = 'uploaded';

    public const string VALIDATED = 'validated';

    public const string IMPORTING = 'importing';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string ROLLED_BACK = 'rolled_back';

    protected $fillable = ['kind', 'original_name', 'path', 'format', 'status', 'options', 'totals', 'created_by_user_id', 'started_at', 'finished_at', 'rolled_back_at', 'failure'];

    protected function casts(): array
    {
        return ['options' => 'array', 'totals' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
