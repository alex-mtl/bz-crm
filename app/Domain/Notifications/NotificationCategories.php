<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use InvalidArgumentException;

/**
 * What a person can be told about (ФО §6.13). Every module registers its categories in its service provider;
 * preferences, the defaults by role and the notification center are built over this list.
 *
 * A category has the channels it uses when nobody decided otherwise; a mandatory category ignores preferences
 * (security, critical notices, sanctions of a moderator).
 */
final class NotificationCategories
{
    public const string IN_APP = 'in_app';

    public const string EMAIL = 'email';

    /** Reserved (Д-23): shown in the settings, delivered nowhere until mobile clients are decided. */
    public const string PUSH = 'push';

    public const array CHANNELS = [self::IN_APP, self::EMAIL, self::PUSH];

    public const array DELIVERABLE = [self::IN_APP, self::EMAIL];

    /** @var array<string, array{code: string, module: string, channels: list<string>, mandatory: bool}> */
    private array $categories = [];

    /**
     * @param  list<string>  $channels  channels on by default
     */
    public function register(string $code, string $module, array $channels = [self::IN_APP], bool $mandatory = false): void
    {
        $this->categories[$code] = ['code' => $code, 'module' => $module, 'channels' => $channels, 'mandatory' => $mandatory];
    }

    /**
     * @return array<string, array{code: string, module: string, channels: list<string>, mandatory: bool}>
     */
    public function all(): array
    {
        return $this->categories;
    }

    /**
     * @return array{code: string, module: string, channels: list<string>, mandatory: bool}
     */
    public function get(string $code): array
    {
        return $this->categories[$code] ?? throw new InvalidArgumentException("Unknown notification category [$code].");
    }

    public function has(string $code): bool
    {
        return isset($this->categories[$code]);
    }

    public function label(string $code): string
    {
        return __('notifications.categories.'.$code);
    }
}
