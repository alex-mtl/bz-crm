<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Actions\SetFieldGroupRights;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Identity\Models\User;
use BackedEnum;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Rights of roles on groups of profile fields (Д-13): a matrix "role × field group". A field is seen only when
 * both this matrix and the owner's own choice allow it.
 */
class FieldRules extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEyeSlash;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.field-rules';

    /** @var array<int, array<string, bool>> role id => field group code (dots as "__") => allowed */
    public array $matrix = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, 'access.field_rules.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.access');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.field_rules.title');
    }

    public function getTitle(): string
    {
        return __('admin.field_rules.title');
    }

    public function mount(): void
    {
        $this->load();
    }

    /**
     * @return array<string, string> key used in the matrix => label
     */
    public function getGroupsProperty(): array
    {
        $groups = [];
        foreach (app(SetFieldGroupRights::class)->codes() as $code) {
            $groups[self::key($code)] = app(PermissionRegistry::class)->get($code)->label();
        }

        return $groups;
    }

    /**
     * @return array<int, string>
     */
    public function getRolesProperty(): array
    {
        return Role::query()->orderBy('id')->get()->mapWithKeys(fn (Role $role): array => [$role->id => $role->name()])->all();
    }

    public function save(): void
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        $codes = app(SetFieldGroupRights::class)->codes();

        try {
            foreach (Role::query()->orderBy('id')->get() as $role) {
                $allowed = array_values(array_filter($codes, fn (string $code): bool => (bool) ($this->matrix[$role->id][self::key($code)] ?? false)));
                app(SetFieldGroupRights::class)($actor, $role, $allowed);
            }
            Notification::make()->title(__('admin.saved'))->success()->send();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
        }
        $this->load();
    }

    private function load(): void
    {
        $codes = app(SetFieldGroupRights::class)->codes();
        $granted = RolePermission::query()->where('effect', PermissionEffect::Allow)->whereIn('permission_code', $codes)->get()
            ->groupBy('role_id')->map(fn ($rows) => $rows->pluck('permission_code')->all());

        $this->matrix = [];
        foreach (Role::query()->orderBy('id')->pluck('id') as $roleId) {
            foreach ($codes as $code) {
                $this->matrix[$roleId][self::key($code)] = in_array($code, $granted->get($roleId, []), true);
            }
        }
    }

    private static function key(string $code): string
    {
        return str_replace('.', '__', $code);
    }
}
