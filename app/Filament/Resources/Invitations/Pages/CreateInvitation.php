<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invitations\Pages;

use App\Domain\Access\Admission\InviteUser;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Invitations\InvitationResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        assert($actor instanceof User);

        try {
            return app(InviteUser::class)(
                $actor,
                (string) $data['email'],
                array_values((array) ($data['role_codes'] ?? [])),
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
                (string) $data['person_type'],
                (int) $data['valid_days'],
            )['invitation'];
        } catch (AuthorizationException|DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin.invitations.sent');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
