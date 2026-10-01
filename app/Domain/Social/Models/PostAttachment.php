<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file of a post: an image, a video or a document. Served only to those who see the post.
 *
 * @property int $id
 * @property int $post_id
 * @property string $kind
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 * @property int $sort_order
 */
class PostAttachment extends Model
{
    protected $table = 'post_attachments';

    protected $fillable = ['post_id', 'kind', 'path', 'original_name', 'mime', 'size', 'sort_order'];
}
