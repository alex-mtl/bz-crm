<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One export: who asked for what, and the file when it is ready (ТЗ §68). Large ones are built in a queue.
 *
 * @property int $id
 * @property string $kind
 * @property string $format
 * @property array<string, mixed>|null $filters
 * @property string $status
 * @property string|null $path
 * @property int|null $row_count
 * @property int $created_by_user_id
 * @property Carbon|null $finished_at
 * @property string|null $failure
 * @property Carbon $created_at
 * @property-read User $creator
 */
class ExportBatch extends Model
{
    public const string QUEUED = 'queued';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    protected $fillable = ['kind', 'format', 'filters', 'status', 'path', 'row_count', 'created_by_user_id', 'finished_at', 'failure'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'finished_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
