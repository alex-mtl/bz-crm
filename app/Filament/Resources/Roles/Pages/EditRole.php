<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Access\Actions\RenameRole;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\Models\Role;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditRole extends EditRecord
{
    use InteractsWithRolePermissions;

    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $role = $this->getRecord();
        assert($role instanceof Role);

        return [...$data, ...$this->groupedPermissions($role)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Role);
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        [$allow, $deny] = $this->flattenPermissions($data);

        return DB::transaction(function () use ($actor, $record, $data, $allow, $deny): Model {
            app(RenameRole::class)($actor, $record, [
                'ro' => $data['name_ro'] ?? null,
                'ru' => $data['name_ru'] ?? null,
                'en' => $data['name_en'] ?? null,
            ]);
            app(SetRolePermissions::class)($actor, $record, $allow, $deny);

            return $record;
        });
    }
}
