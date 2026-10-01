<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An answer option of a poll inside a post.
 *
 * @property int $id
 * @property int $post_id
 * @property string $text
 * @property int $sort_order
 */
class PollOption extends Model
{
    public $timestamps = false;

    protected $table = 'post_poll_options';

    protected $fillable = ['post_id', 'text', 'sort_order'];
}
