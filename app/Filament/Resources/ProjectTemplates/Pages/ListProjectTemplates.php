<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\ManageProjects;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;

class ListProjectTemplates extends ListRecords
{
    protected static string $resource = ProjectTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('admin.templates.create'))
                ->schema(ProjectTemplateResource::fields())
                ->action(function (array $data): void {
                    $actor = Filament::auth()->user();
                    assert($actor instanceof User);
                    try {
                        app(ManageProjects::class)->saveTemplate($actor, (string) $data['name'], $data['description'] ?? null, ProjectTemplateResource::structure($data));
                        Notification::make()->title(__('admin.saved'))->success()->send();
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
