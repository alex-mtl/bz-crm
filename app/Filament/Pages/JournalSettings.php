<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Actions\UpdateRetentionPolicy;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\RetentionPolicy;
use App\Domain\Identity\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Journal retention by category (ADR-006). Only the retention policy changes here; purging is a separate command.
 *
 * @property-read Schema $form
 */
class JournalSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 51;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, 'audit.settings.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.journal_settings.title');
    }

    public function getTitle(): string
    {
        return __('admin.journal_settings.title');
    }

    public function mount(): void
    {
        $this->form->fill(app(RetentionPolicy::class)->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(2)->components(array_map(
            fn (EventCategory $category): TextInput => TextInput::make($category->value)
                ->label(__('journal.categories.'.$category->value))
                ->numeric()->integer()->minValue(1)->suffix(__('admin.journal_settings.days'))
                ->placeholder(__('admin.journal_settings.forever')),
            EventCategory::cases(),
        ));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(__('admin.journal_settings.intro')),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('admin.save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);
        app(AuthorizationService::class)->authorize($user, 'audit.settings.manage');

        $days = [];
        foreach ((array) $this->form->getState() as $category => $value) {
            $days[(string) $category] = is_numeric($value) ? (int) $value : null;
        }
        app(UpdateRetentionPolicy::class)($days, $user->id);

        Notification::make()->title(__('admin.saved'))->success()->send();
    }
}
