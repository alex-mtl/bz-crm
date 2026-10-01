<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The text of a post as it was before an edit — the edit history moderators read (ФО §6.4.1).
 *
 * @property int $id
 * @property int $post_id
 * @property string|null $body
 * @property int|null $edited_by_user_id
 * @property Carbon $created_at
 */
class PostRevision extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'post_revisions';

    protected $fillable = ['post_id', 'body', 'edited_by_user_id'];
}
