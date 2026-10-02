<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Chat;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The discussion of an object — a task, a project, a group — is read and written by exactly those who may read
 * the object (ТЗ §21). The module that owns the object says who that is: it registers a resolver here, and the
 * messenger asks it instead of knowing anything about tasks or groups.
 */
final class ChatSubjects
{
    /** @var array<string, array{access: Closure(User, Model): bool, title: Closure(Model): string, url: Closure(Model): (string|null)}> */
    private array $resolvers = [];

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(User, Model): bool  $access
     * @param  Closure(Model): string  $title
     * @param  Closure(Model): (string|null)  $url
     */
    public function register(string $model, Closure $access, Closure $title, Closure $url): void
    {
        $this->resolvers[(new $model)->getMorphClass()] = ['access' => $access, 'title' => $title, 'url' => $url];
    }

    public function subjectOf(Chat $chat): ?Model
    {
        if ($chat->subject_type === null || $chat->subject_id === null) {
            return null;
        }
        $class = Relation::getMorphedModel($chat->subject_type) ?? $chat->subject_type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class::query()->find($chat->subject_id) : null;
    }

    /**
     * Unknown subject type or a vanished subject — no access: a discussion never outlives its object's rules.
     */
    public function mayAccess(User $user, Chat $chat): bool
    {
        $resolver = $this->resolvers[$chat->subject_type ?? ''] ?? null;
        $subject = $resolver !== null ? $this->subjectOf($chat) : null;

        return $resolver !== null && $subject !== null && $resolver['access']($user, $subject);
    }

    public function title(Chat $chat): ?string
    {
        $resolver = $this->resolvers[$chat->subject_type ?? ''] ?? null;
        $subject = $resolver !== null ? $this->subjectOf($chat) : null;

        return $resolver !== null && $subject !== null ? $resolver['title']($subject) : null;
    }

    public function url(Chat $chat): ?string
    {
        $resolver = $this->resolvers[$chat->subject_type ?? ''] ?? null;
        $subject = $resolver !== null ? $this->subjectOf($chat) : null;

        return $resolver !== null && $subject !== null ? $resolver['url']($subject) : null;
    }
}
