<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment under a post (ФО §6.4.3): a tree by parent_id, optionally quoting another comment.
 * It has no visibility of its own — it is seen exactly by those who see the post.
 *
 * @property int $id
 * @property int $post_id
 * @property int|null $parent_id
 * @property int|null $quoted_comment_id
 * @property int $depth
 * @property int $author_person_id
 * @property string $body
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by_user_id
 * @property string|null $hidden_reason
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property-read Post $post
 * @property-read Person $author
 * @property-read Comment|null $quoted
 */
class Comment extends Model
{
    public const int MAX_DEPTH = 8;

    protected $table = 'post_comments';

    protected $attributes = ['depth' => 0];

    protected $fillable = ['post_id', 'parent_id', 'quoted_comment_id', 'depth', 'author_person_id', 'body', 'hidden_at', 'hidden_by_user_id', 'hidden_reason', 'deleted_at'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function quoted(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_comment_id');
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }
}
