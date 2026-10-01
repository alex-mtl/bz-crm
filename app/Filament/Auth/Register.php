<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Domain\Identity\Actions\RegisterWithEmail;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Self-registration by e-mail (ФО §6.1). The domain action creates Person + User + application;
 * the page only collects the form and signs the applicant in (they then see only the status page).
 */
class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->label(__('identity.fields.first_name'))->required()->maxLength(100)->autofocus(),
            TextInput::make('last_name')->label(__('identity.fields.last_name'))->maxLength(100),
            $this->getEmailFormComponent(),
            // The domain action receives the plain password; the model's cast hashes it once.
            TextInput::make('password')
                ->label(__('filament-panels::auth/pages/register.form.password.label'))
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rule(Password::default())
                ->showAllValidationMessages()
                ->same('passwordConfirmation')
                ->validationAttribute(__('filament-panels::auth/pages/register.form.password.validation_attribute')),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    public function register(): ?RegistrationResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        /** @var array{first_name: string, last_name: ?string, email: string, password: string} $data */
        $data = $this->form->getState();

        try {
            $user = app(RegisterWithEmail::class)(
                $data['first_name'],
                $data['last_name'] ?? null,
                $data['email'],
                $data['password'],
                app()->getLocale(),
            );
        } catch (IdentityRuleViolation $exception) {
            throw ValidationException::withMessages(['data.email' => $exception->getMessage()]);
        }

        Filament::auth()->login($user);
        session()->regenerate();

        return app(RegistrationResponse::class);
    }
}
