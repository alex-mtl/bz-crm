<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament is a thin layer: every visibility check asks the one authorization service (ТЗ §15),
 * and the domain action re-checks on execution — hiding a button is not a check.
 */
trait ChecksPermissions
{
    protected static function allows(string $code, ?object $subject = null): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, $code, $subject);
    }

    /**
     * The list shows exactly what the code allows, filtered in SQL (ТЗ §52: lists, search, counters).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function scoped(Builder $query, string $code): Builder
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(AuthorizationService::class)->scopeQuery($user, $code, $query);
    }

    protected static function actor(): User
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /**
     * Runs a domain action and turns a refusal into a notification instead of an error page.
     */
    protected static function attempt(callable $action, ?string $successTitle = null): bool
    {
        try {
            $action();
        } catch (AuthorizationException|DomainException $exception) {
            Notification::make()->title($exception->getMessage() !== '' ? $exception->getMessage() : __('access.denied'))->danger()->send();

            return false;
        }

        if ($successTitle !== null) {
            Notification::make()->title($successTitle)->success()->send();
        }

        return true;
    }
}
