<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs\Pages;

use App\Domain\Catalogs\Actions\CreateCatalogItem as CreateCatalogItemAction;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Catalogs\CatalogItemResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateCatalogItem extends CreateRecord
{
    protected static string $resource = CatalogItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);
        $catalog = (string) $data['catalog_code'];
        $locale = app()->getLocale();
        $names = ['ro' => $data['name_ro'] ?? null, 'ru' => $data['name_ru'] ?? null, 'en' => $data['name_en'] ?? null];
        // The language the admin typed in is the source for auto-copies (Д-16).
        $source = filled($names[$locale] ?? null) ? $locale : (string) array_key_first(array_filter($names, 'filled'));

        try {
            return app(CreateCatalogItemAction::class)(
                $actor, $catalog, $names, $source,
                (array) ($data['properties'][$catalog] ?? []),
                filled($data['code'] ?? null) ? (string) $data['code'] : null,
            );
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
