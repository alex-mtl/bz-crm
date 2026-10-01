<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthProviders\Pages;

use App\Domain\Identity\Actions\SaveAuthProvider;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\AuthProviders\AuthProviderResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAuthProvider extends EditRecord
{
    protected static string $resource = AuthProviderResource::class;

    /**
     * The secret never travels back to the browser: the field starts empty, "empty" means "keep".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['client_secret']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof AuthProvider);
        $actor = Filament::auth()->user();
        assert($actor instanceof User);

        return app(SaveAuthProvider::class)($actor, $data, $record);
    }
}
