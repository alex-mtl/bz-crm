<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\People\Models\Person;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Account for signing in (ТЗ §12). The human behind it is always a Person.
 *
 * @property int $id
 * @property int $person_id
 * @property string|null $email
 * @property string|null $password
 * @property UserStatus $status
 * @property string $locale
 * @property int $session_epoch
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deactivated_at
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 * @property-read Person $person
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'person_id', 'email', 'password', 'status', 'locale', 'email_verified_at', 'last_login_at', 'deactivated_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'session_epoch' => 'integer',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * An account created through a provider may have no login e-mail of its own (Д-10):
     * mail then goes to the address on the person's card.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email ?? $this->person->email;
    }

    /**
     * Notifications and letters are written in the language of the one who reads them, not of the one who caused them.
     */
    public function preferredLocale(): ?string
    {
        return in_array($this->locale, (array) config('app.supported_locales'), true) ? $this->locale : null;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * A deactivated account cannot sign in at all (ФО §6.1). An applicant can, but the panel
     * sends them to the application status page (RedirectApplicantsToStatusPage).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status !== UserStatus::Deactivated;
    }

    /**
     * An account without a login e-mail (created through a provider, Д-10) has nothing to confirm.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email === null || $this->email_verified_at !== null;
    }

    /**
     * Until approved and with the e-mail confirmed, the user sees only the account status page (ФО §6.1).
     */
    public function mustSeeStatusPage(): bool
    {
        return $this->isApplicant() || ! $this->hasVerifiedEmail();
    }

    public function isApplicant(): bool
    {
        return in_array($this->status, [UserStatus::PendingApproval, UserStatus::Rejected], true);
    }

    public function getFilamentName(): string
    {
        return $this->person->fullName();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    /**
     * Called by Filament when the user sets up or removes 2FA in the profile; the change is a security event.
     */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $wasEnabled = $this->hasAppAuthentication();

        DB::transaction(function () use ($secret, $wasEnabled): void {
            $this->app_authentication_secret = $secret;
            $this->save();
            if ($wasEnabled !== filled($secret)) {
                app(EventJournal::class)->record(filled($secret) ? 'identity.two_factor.enabled' : 'identity.two_factor.disabled', $this);
            }
        });
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email ?? $this->person->fullName();
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function hasAppAuthentication(): bool
    {
        return filled($this->app_authentication_secret);
    }
}
