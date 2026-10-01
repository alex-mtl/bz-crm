<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Domain\Identity\Actions\DisconnectProvider;
use App\Domain\Identity\Actions\UpdateOwnAccount;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Profiles\Enums\FieldVisibility;
use App\Domain\Profiles\Models\PersonContact;
use App\Domain\Profiles\Models\PersonProfile;
use App\Filament\Support\Options;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * Own account: name, interface language, password, two-factor authentication (ФО §3.6, §6.1)
 * and connected sign-in methods (ФО §6.1: link / unlink in the profile).
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->label(__('identity.fields.first_name'))->required()->maxLength(100),
            TextInput::make('last_name')->label(__('identity.fields.last_name'))->maxLength(100),
            TextInput::make('email')->label(__('identity.fields.email'))->disabled()->dehydrated(false),
            Select::make('locale')
                ->label(__('identity.fields.locale'))
                ->options(fn (): array => collect((array) config('app.supported_locales'))
                    ->mapWithKeys(fn (string $code): array => [$code => __('identity.locales.'.$code)])->all())
                ->required()
                ->selectablePlaceholder(false),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
            ...$this->openLayerComponents(),
        ]);
    }

    /**
     * The open layer (ФО §6.3.1): the owner chooses who sees each field (Д-13).
     *
     * @return list<Component>
     */
    private function openLayerComponents(): array
    {
        $visibility = FieldVisibility::options();

        return [
            Section::make(__('admin.people.open_profile'))->columns(2)->schema([
                Select::make('profile.cover_code')->label(__('profiles.fields.cover'))->options(fn (): array => Options::catalog('profile_covers')),
                Textarea::make('profile.bio')->label(__('profiles.fields.bio'))->maxLength(2000)->columnSpanFull(),
                DatePicker::make('profile.birth_date')->label(__('profiles.fields.birth_date'))->maxDate(now()),
                Select::make('profile.gender')->label(__('profiles.fields.gender'))
                    ->options(['female' => __('profiles.gender.female'), 'male' => __('profiles.gender.male')]),
                Select::make('profile.personal_visibility')->label(__('profiles.fields.personal_visibility'))->options($visibility)->required()->default('management'),
                TagsInput::make('profile.skills')->label(__('profiles.fields.skills')),
                TagsInput::make('profile.interests')->label(__('profiles.fields.interests')),
                TagsInput::make('profile.languages')->label(__('profiles.fields.languages')),
                Select::make('profile.skills_visibility')->label(__('profiles.fields.skills_visibility'))->options($visibility)->required()->default('all'),
                Repeater::make('contacts')->label(__('profiles.fields.contacts'))->columns(3)->columnSpanFull()->defaultItems(0)->schema([
                    Select::make('contact_type')->label(__('profiles.fields.contact_type'))->options(fn (): array => Options::catalog('contact_types'))->required(),
                    TextInput::make('value')->label(__('profiles.fields.contact_value'))->required()->maxLength(255),
                    Select::make('visibility')->label(__('profiles.fields.visibility'))->options($visibility)->required()->default('colleagues'),
                ]),
            ]),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            $this->getConnectedProvidersComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->currentUser();

        return [
            ...$data,
            'first_name' => $user->person->first_name,
            'last_name' => $user->person->last_name,
            'email' => $user->email ?? $user->person->email,
            'profile' => [
                'personal_visibility' => 'management', 'skills_visibility' => 'all',
                ...(PersonProfile::query()->find($user->person_id)?->only(['cover_code', 'bio', 'birth_date', 'gender', 'personal_visibility', 'skills', 'interests', 'languages', 'skills_visibility']) ?? []),
            ],
            'contacts' => PersonContact::query()->where('person_id', $user->person_id)->orderBy('sort_order')->get()
                ->map(fn (PersonContact $c): array => ['contact_type' => $c->contact_type, 'value' => $c->value, 'visibility' => $c->visibility->value])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        /** @var User $record */
        app(UpdateOwnAccount::class)(
            $record,
            (string) $data['first_name'],
            isset($data['last_name']) ? (string) $data['last_name'] : null,
            (string) $data['locale'],
            isset($data['password']) ? (string) $data['password'] : null,
        );
        $profile = (array) ($data['profile'] ?? []);
        foreach (['personal_visibility', 'skills_visibility'] as $key) {
            if ($profile[$key] instanceof FieldVisibility) {
                $profile[$key] = $profile[$key]->value;
            }
        }
        app(ManageProfile::class)->update($record, $record->person, $profile, array_values(array_map(fn (array $c): array => [
            'contact_type' => (string) $c['contact_type'], 'value' => (string) $c['value'], 'visibility' => (string) $c['visibility'],
        ], (array) ($data['contacts'] ?? []))));

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        // A changed interface language applies on the next page load.
        return static::getUrl();
    }

    protected function getConnectedProvidersComponent(): Component
    {
        $user = $this->currentUser();
        $linked = SocialIdentity::query()->where('user_id', $user->id)->get()->keyBy('provider');
        $providers = AuthProvider::query()->usable()->get();

        $rows = $providers->map(function (AuthProvider $provider) use ($linked): Component {
            $identity = $linked->get($provider->code);

            return Actions::make([
                $identity === null
                    ? Action::make('connect_'.$provider->code)
                        ->label(__('identity.profile.connect', ['provider' => $provider->display_name]))
                        ->url(route('oauth.redirect', $provider->code))
                    : Action::make('disconnect_'.$provider->code)
                        ->label(__('identity.profile.disconnect', ['provider' => $provider->display_name]))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn () => $this->disconnect($identity->id)),
            ]);
        })->all();

        return Section::make(__('identity.profile.connected_providers'))
            ->compact()
            ->secondary()
            ->schema($rows === [] ? [Text::make(__('identity.profile.no_providers'))] : $rows);
    }

    public function disconnect(int $identityId): void
    {
        $identity = SocialIdentity::query()->findOrFail($identityId);

        try {
            app(DisconnectProvider::class)($this->currentUser(), $identity);
        } catch (IdentityRuleViolation $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('identity.profile.disconnected'))->success()->send();
        $this->redirect(static::getUrl());
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);

        return $user;
    }
}
