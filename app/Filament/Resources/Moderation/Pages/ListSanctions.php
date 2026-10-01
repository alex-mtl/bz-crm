<?php

declare(strict_types=1);

namespace App\Filament\Resources\Moderation\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Social\Moderation;
use App\Filament\Resources\Moderation\ReportResource;
use App\Filament\Resources\Moderation\SanctionResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

class ListSanctions extends ListRecords
{
    protected static string $resource = SanctionResource::class;

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function attempt(callable $action, string $success): void
    {
        try {
            $action();
            Notification::make()->title($success)->success()->send();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $can = fn (string $code): bool => app(AuthorizationService::class)->can($this->actor(), $code);

        return [
            Action::make('queue')->label(__('social.moderation.queue'))->icon('heroicon-o-flag')->color('gray')->url(ReportResource::getUrl('index')),
            Action::make('warn')->label(__('social.moderation.warn'))->icon('heroicon-o-exclamation-triangle')->color('warning')
                ->visible(fn (): bool => $can('moderation.warn'))
                ->schema([SanctionResource::personField(), ...ReportResource::reasonField()])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(Moderation::class)->warn($this->actor(), Person::query()->findOrFail($data['person_id']), (string) $data['reason']),
                    __('social.moderation.warned'),
                )),
            Action::make('mute')->label(__('social.moderation.mute'))->icon('heroicon-o-speaker-x-mark')->color('danger')
                ->visible(fn (): bool => $can('moderation.mute'))
                ->schema([
                    SanctionResource::personField(),
                    DateTimePicker::make('until')->label(__('social.moderation.mute_until'))->seconds(false)->required()->default(fn (): Carbon => now()->addDay()),
                    ...ReportResource::reasonField(),
                ])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(Moderation::class)->mute($this->actor(), Person::query()->findOrFail($data['person_id']), Carbon::parse($data['until']), (string) $data['reason']),
                    __('social.moderation.muted'),
                )),
        ];
    }
}
