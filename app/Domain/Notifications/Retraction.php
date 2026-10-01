<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ §37: a notification must not reveal what its recipient may not see. A notification is sent only to those
 * who see its subject; when access is lost afterwards — an invitation withdrawn, a post hidden, an audience
 * narrowed — the stored notification is emptied: the bell and the center keep the fact, not the content.
 *
 * Subjects are short codes chosen by the modules ("post", "event", "group"), not class names.
 */
final class Retraction
{
    /**
     * Users who hold a notification about the subject that still has its content.
     *
     * @return list<int>
     */
    public function recipientsOf(string $subjectType, int $subjectId): array
    {
        return DB::table('notifications')->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('notifiable_type', (new User)->getMorphClass())->whereNull('retracted_at')
            ->distinct()->pluck('notifiable_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Empties the notifications about the subject held by those who no longer pass the check.
     *
     * @param  callable(User): bool  $stillSees
     */
    public function retractFromThoseWhoLostAccess(string $subjectType, int $subjectId, callable $stillSees): int
    {
        $lost = [];
        foreach (User::query()->whereKey($this->recipientsOf($subjectType, $subjectId))->get() as $user) {
            if (! $stillSees($user)) {
                $lost[] = $user->id;
            }
        }

        return $this->retract($subjectType, $subjectId, $lost);
    }

    /**
     * @param  list<int>|null  $userIds  only these recipients; null — everyone
     */
    public function retract(string $subjectType, int $subjectId, ?array $userIds = null): int
    {
        if ($userIds === []) {
            return 0;
        }
        $query = DB::table('notifications')->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('notifiable_type', (new User)->getMorphClass())->whereNull('retracted_at');
        if ($userIds !== null) {
            $query->whereIn('notifiable_id', $userIds);
        }
        $rows = $query->get(['id', 'notifiable_id']);
        $locales = User::query()->whereKey($rows->pluck('notifiable_id')->unique()->all())->pluck('locale', 'id');

        foreach ($rows as $row) {
            DB::table('notifications')->where('id', $row->id)->update([
                'retracted_at' => now(),
                'read_at' => now(),
                'data' => json_encode([
                    'format' => 'filament',
                    'title' => __('notifications.retracted', [], $locales[$row->notifiable_id] ?? null),
                    'body' => null,
                    'icon' => 'heroicon-o-eye-slash',
                    'iconColor' => 'gray',
                    'duration' => 'persistent',
                    'actions' => [],
                    'kind' => 'retracted',
                ]),
            ]);
        }

        return $rows->count();
    }
}
