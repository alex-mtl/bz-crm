<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Access\Actions\CreateRole as CreateRoleAction;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateRole extends CreateRecord
{
    use InteractsWithRolePermissions;

    protected static string $resource = RoleResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        [$allow, $deny] = $this->flattenPermissions($data);

        return DB::transaction(function () use ($actor, $data, $allow, $deny): Model {
            $role = app(CreateRoleAction::class)($actor, (string) $data['code'], [
                'ro' => $data['name_ro'] ?? null,
                'ru' => $data['name_ru'] ?? null,
                'en' => $data['name_en'] ?? null,
            ], app()->getLocale());
            app(SetRolePermissions::class)($actor, $role, $allow, $deny);

            return $role;
        });
    }
}
