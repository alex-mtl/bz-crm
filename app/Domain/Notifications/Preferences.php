<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Exceptions\NotificationRuleViolation;
use App\Domain\Notifications\Models\NotificationDefault;
use App\Domain\Notifications\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * Who is told what and where (ФО §6.13): the person's own choice, else the defaults of their roles, else the
 * default of the category. A mandatory category is not a matter of choice.
 */
final class Preferences
{
    /** @var array<int, array<string, array<string, bool>>> user id => category => channel => enabled */
    private array $resolved = [];

    public function __construct(
        private readonly NotificationCategories $categories,
        private readonly AuthorizationService $authorization,
        private readonly EventJournal $journal,
    ) {}

    public function enabled(User $user, string $category, string $channel): bool
    {
        return $this->matrix($user)[$category][$channel] ?? false;
    }

    /**
     * The effective settings of the user: category => channel => enabled.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(User $user): array
    {
        if (isset($this->resolved[$user->id])) {
            return $this->resolved[$user->id];
        }
        $own = NotificationPreference::query()->where('user_id', $user->id)->get()->groupBy('category');
        $byRole = NotificationDefault::query()
            ->whereIn('role_id', UserRole::query()->inEffect()->where('user_id', $user->id)->select('role_id'))
            ->get()->groupBy('category');

        $matrix = [];
        foreach ($this->categories->all() as $code => $category) {
            foreach (NotificationCategories::DELIVERABLE as $channel) {
                $default = in_array($channel, $category['channels'], true);
                if ($category['mandatory']) {
                    $matrix[$code][$channel] = $default;

                    continue;
                }
                $personal = $own->get($code)?->firstWhere('channel', $channel);
                $roleRows = $byRole->get($code)?->where('channel', $channel) ?? collect();
                $matrix[$code][$channel] = match (true) {
                    $personal !== null => (bool) $personal->enabled,
                    // Several roles: the channel is on if any of them turns it on.
                    $roleRows->isNotEmpty() => $roleRows->contains(fn (NotificationDefault $row): bool => (bool) $row->enabled),
                    default => $default,
                };
            }
        }

        return $this->resolved[$user->id] = $matrix;
    }

    public function set(User $user, string $category, string $channel, bool $enabled): void
    {
        $this->authorization->authorize($user, 'notifications.preferences');
        $this->ensureChangeable($category, $channel);

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'category' => $category, 'channel' => $channel], ['enabled' => $enabled],
        );
        unset($this->resolved[$user->id]);
    }

    /**
     * Returns the user to the defaults of their roles.
     */
    public function reset(User $user): void
    {
        $this->authorization->authorize($user, 'notifications.preferences');
        NotificationPreference::query()->where('user_id', $user->id)->delete();
        unset($this->resolved[$user->id]);
    }

    /**
     * The default of a role; null removes the row — the role falls back to the default of the category.
     */
    public function setDefault(User $actor, Role $role, string $category, string $channel, ?bool $enabled): void
    {
        $this->authorization->authorize($actor, 'notifications.defaults.manage');
        $this->ensureChangeable($category, $channel);
        $key = ['role_id' => $role->id, 'category' => $category, 'channel' => $channel];
        $old = NotificationDefault::query()->where($key)->value('enabled');
        if (($old !== null ? (bool) $old : null) === $enabled) {
            return;
        }

        DB::transaction(function () use ($role, $key, $enabled, $old, $category, $channel): void {
            $enabled === null
                ? NotificationDefault::query()->where($key)->delete()
                : NotificationDefault::query()->updateOrCreate($key, ['enabled' => $enabled]);
            $this->journal->record('notifications.defaults.changed', $role,
                ['enabled' => $old !== null ? (bool) $old : null], ['category' => $category, 'channel' => $channel, 'enabled' => $enabled]);
        });
        $this->resolved = [];
    }

    /**
     * The defaults of a role as stored: category => channel => enabled (absent = the default of the category).
     *
     * @return array<string, array<string, bool>>
     */
    public function defaultsOf(Role $role): array
    {
        $rows = [];
        foreach (NotificationDefault::query()->where('role_id', $role->id)->get() as $row) {
            $rows[$row->category][$row->channel] = (bool) $row->enabled;
        }

        return $rows;
    }

    public function forget(): void
    {
        $this->resolved = [];
    }

    private function ensureChangeable(string $category, string $channel): void
    {
        if (! $this->categories->has($category) || ! in_array($channel, NotificationCategories::DELIVERABLE, true)) {
            throw NotificationRuleViolation::because('unknown_setting');
        }
        if ($this->categories->get($category)['mandatory']) {
            throw NotificationRuleViolation::because('mandatory_category');
        }
    }
}
