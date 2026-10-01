<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Permission simulator (ФО §6.2, plan 2b): the system "through the eyes" of a user — which rights they have,
 * in which scope, and where each territory of their access comes from. Opening someone's picture is journaled.
 */
class AccessSimulator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.access-simulator';

    public ?int $userId = null;

    public string $search = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, 'access.simulate');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.access');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.simulator.title');
    }

    public function getTitle(): string
    {
        return __('admin.simulator.title');
    }

    /**
     * @return array<int, string>
     */
    public function getCandidatesProperty(): array
    {
        if (mb_strlen($this->search) < 2) {
            return [];
        }

        return User::query()->with('person')
            ->whereHas('person', fn ($q) => $q->where('first_name', 'like', "%{$this->search}%")->orWhere('last_name', 'like', "%{$this->search}%"))
            ->limit(15)->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->person->fullName().($u->email ? ' · '.$u->email : '')])->all();
    }

    public function pick(int $userId): void
    {
        $this->userId = $userId;
        $this->search = '';
        app(EventJournal::class)->record('access.simulated', User::query()->find($userId));
    }

    /**
     * @return array{user: User, roles: list<array{role: string, scope: string, kind: string, until: ?string}>,
     *               territories: list<array{territory: string, origin: string, until: ?string}>,
     *               codes: array<string, list<array{code: string, label: string, scope: string}>>}|null
     */
    public function getPictureProperty(): ?array
    {
        $user = $this->userId !== null ? User::query()->with('person')->find($this->userId) : null;
        if ($user === null) {
            return null;
        }
        $authorization = app(AuthorizationService::class);
        $authorization->forget($user);

        $roles = UserRole::query()->with('role')->where('user_id', $user->id)->inEffect()->get()->map(fn (UserRole $a): array => [
            'role' => $a->role->name(),
            'scope' => $this->scopeLabel(ScopeType::fromStored($a->scope_type), $a->scope_id),
            'kind' => __('admin.simulator.kind.'.$a->kind),
            'until' => $a->expires_at?->isoFormat('LL'),
        ])->all();

        $territories = collect(app(TerritorialAccess::class)->explain($user->person_id))->map(fn (array $row): array => [
            'territory' => $row['territory']->name(),
            'origin' => $row['source'] === 'unit'
                ? __('admin.people.from_unit', ['unit' => $row['unit']?->name])
                : __('admin.people.granted_by', ['name' => User::query()->with('person')->find($row['grant']?->granted_by_user_id)?->person->fullName() ?? '—']),
            'until' => $row['grant']?->expires_at?->isoFormat('LL'),
        ])->all();

        $codes = [];
        foreach (app(PermissionRegistry::class)->all() as $definition) {
            $grants = $authorization->grantsFor($user, $definition->code);
            if ($grants === []) {
                continue;
            }
            $scopes = collect($grants)->map(fn (array $g): string => $g['data'] !== null
                ? __('admin.simulator.data.'.$g['data'])
                : $this->scopeLabel($g['scope'], $g['scope_id']))->unique()->implode(', ');
            $codes[__('admin.modules.'.$definition->module)][] = ['code' => $definition->code, 'label' => $definition->label(), 'scope' => $scopes];
        }
        ksort($codes);

        return ['user' => $user, 'roles' => $roles, 'territories' => $territories, 'codes' => $codes];
    }

    private function scopeLabel(ScopeType $scope, ?int $id): string
    {
        return match ($scope) {
            ScopeType::OrgUnit => $scope->label().': '.OrgUnit::query()->whereKey($id)->value('name'),
            ScopeType::Territory => $scope->label().': '.Territory::query()->find($id)?->name(),
            default => $scope->label(),
        };
    }
}
