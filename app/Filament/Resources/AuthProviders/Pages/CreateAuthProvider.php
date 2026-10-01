<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthProviders\Pages;

use App\Domain\Identity\Actions\SaveAuthProvider;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\AuthProviders\AuthProviderResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAuthProvider extends CreateRecord
{
    protected static string $resource = AuthProviderResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);

        return app(SaveAuthProvider::class)($actor, $data);
    }
}
