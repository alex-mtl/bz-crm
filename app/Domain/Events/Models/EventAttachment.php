<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file of an event: a document attached to it or a photo of the photo report (ФО §6.7).
 *
 * @property int $id
 * @property int $event_id
 * @property string $kind
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 */
class EventAttachment extends Model
{
    protected $table = 'event_attachments';

    protected $fillable = ['event_id', 'kind', 'path', 'original_name', 'mime', 'size', 'uploaded_by_user_id'];
}
