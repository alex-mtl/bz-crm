<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file of a message: a document, a photo, a video, a voice recording. It can be opened only after the
 * antivirus check let it through — or when no scanner is configured at all (ФО §6.6.4, ADR-012).
 *
 * @property int $id
 * @property int $message_id
 * @property string $kind
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 * @property string $scan_status
 * @property Carbon|null $scanned_at
 * @property-read Message $message
 */
class MessageAttachment extends Model
{
    public const string PENDING = 'pending';

    public const string CLEAN = 'clean';

    public const string INFECTED = 'infected';

    public const string SKIPPED = 'skipped';

    protected $fillable = ['message_id', 'kind', 'path', 'original_name', 'mime', 'size', 'scan_status', 'scanned_at'];

    protected $attributes = ['scan_status' => self::PENDING];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isAvailable(): bool
    {
        return in_array($this->scan_status, [self::CLEAN, self::SKIPPED], true);
    }
}
