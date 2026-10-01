<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\Models\Group;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A post of the internal social network (ФО §6.4.1). Stored once; its audience is described by the visibility
 * level and the rows of post_territories / post_groups / post_targets.
 *
 * @property int $id
 * @property int $author_person_id
 * @property string|null $body
 * @property int|null $repost_of_post_id
 * @property string $status
 * @property string $visibility
 * @property Carbon|null $publish_at
 * @property Carbon|null $published_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by_user_id
 * @property string|null $hidden_reason
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property-read Person $author
 * @property-read Post|null $original
 */
class Post extends Model
{
    public const string DRAFT = 'draft';

    public const string SCHEDULED = 'scheduled';

    public const string PUBLISHED = 'published';

    public const string PUBLIC = 'public';

    public const string REGIONAL = 'regional';

    public const string GROUP = 'group';

    public const string PRIVATE = 'private';

    public const string TARGETED = 'targeted';

    public const array VISIBILITIES = [self::PUBLIC, self::REGIONAL, self::GROUP, self::PRIVATE, self::TARGETED];

    protected $fillable = [
        'author_person_id', 'body', 'repost_of_post_id', 'status', 'visibility', 'publish_at', 'published_at',
        'edited_at', 'hidden_at', 'hidden_by_user_id', 'hidden_reason', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime', 'published_at' => 'datetime', 'edited_at' => 'datetime',
            'hidden_at' => 'datetime', 'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repost_of_post_id');
    }

    /**
     * @return BelongsToMany<Territory, $this>
     */
    public function territories(): BelongsToMany
    {
        return $this->belongsToMany(Territory::class, 'post_territories');
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'post_groups');
    }

    /**
     * @return HasMany<PostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * @return HasMany<PostAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PostRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class)->orderBy('id');
    }

    /**
     * @return HasMany<PollOption, $this>
     */
    public function pollOptions(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PostPin, $this>
     */
    public function pins(): HasMany
    {
        return $this->hasMany(PostPin::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED && $this->deleted_at === null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }
}
