<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectTemplates;

use App\Domain\Projects\Actions\ManageProjects;
use App\Domain\Projects\Models\ProjectTemplate;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Project templates — "a project in two clicks" (ФО §6.8.1): phases with offsets and their tasks.
 */
class ProjectTemplateResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ProjectTemplate::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'project-templates';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.work');
    }

    public static function getModelLabel(): string
    {
        return __('admin.templates.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.templates.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('projects.templates.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
            Textarea::make('description')->label(__('admin.tasks.description')),
            Toggle::make('strict_phases')->label(__('admin.projects.strict_phases')),
            Repeater::make('phases')->label(__('admin.projects.phases'))->defaultItems(0)->collapsible()->schema([
                TextInput::make('name')->label(__('admin.fields.name'))->required(),
                TextInput::make('offset_days')->label(__('admin.templates.offset'))->numeric()->integer()->default(0),
                TextInput::make('duration_days')->label(__('admin.templates.duration'))->numeric()->integer()->default(7),
                Repeater::make('tasks')->label(__('admin.tasks.plural'))->defaultItems(0)->columns(3)->schema([
                    TextInput::make('title')->label(__('admin.tasks.title'))->required(),
                    Select::make('type_code')->label(__('admin.tasks.type'))->options(fn (): array => Options::catalog('task_types'))->default('assignment'),
                    TextInput::make('offset_days')->label(__('admin.templates.offset'))->numeric()->integer()->default(0),
                ]),
            ])->columns(3),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function structure(array $data): array
    {
        $phases = array_values((array) ($data['phases'] ?? []));

        return [
            'structure' => $phases === [] ? 'flat' : 'phases',
            'strict_phases' => (bool) ($data['strict_phases'] ?? false),
            'phases' => array_map(fn (array $p): array => [...$p, 'tasks' => array_values((array) ($p['tasks'] ?? []))], $phases),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name')),
                TextColumn::make('phases')->label(__('admin.projects.phases'))
                    ->state(fn (ProjectTemplate $record): int => count((array) ($record->structure['phases'] ?? []))),
                TextColumn::make('updated_at')->label(__('admin.fields.created_at'))->dateTime(),
            ])
            ->recordActions([
                Action::make('edit')->label(__('admin.org_units.edit'))
                    ->fillForm(fn (ProjectTemplate $record): array => [
                        'name' => $record->name, 'description' => $record->description,
                        'strict_phases' => (bool) ($record->structure['strict_phases'] ?? false),
                        'phases' => $record->structure['phases'] ?? [],
                    ])
                    ->schema(static::fields())
                    ->action(fn (ProjectTemplate $record, array $data) => static::attempt(fn () => app(ManageProjects::class)->saveTemplate(
                        static::actor(), (string) $data['name'], $data['description'] ?? null, static::structure($data), $record,
                    ), __('admin.saved'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProjectTemplates::route('/')];
    }
}
