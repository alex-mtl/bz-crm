<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One vote: a person answers a poll once.
 *
 * @property int $id
 * @property int $post_id
 * @property int $option_id
 * @property int $person_id
 */
class PollVote extends Model
{
    protected $table = 'post_poll_votes';

    protected $fillable = ['post_id', 'option_id', 'person_id'];
}
