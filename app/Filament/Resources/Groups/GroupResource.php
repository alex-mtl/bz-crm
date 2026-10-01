<?php

declare(strict_types=1);

namespace App\Filament\Resources\Groups;

use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Projects\Models\Project;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Pages\ViewGroup;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Groups (ФО §6.5). The list is GroupAccess::visible(): a secret group is shown — and counted — only to its members.
 */
class GroupResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Group::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'groups';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.social');
    }

    public static function getModelLabel(): string
    {
        return __('groups.ui.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('groups.ui.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('groups.id', app(GroupAccess::class)->visible(static::actor())->select('groups.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('groups.read');
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Group && app(GroupAccess::class)->canSee(static::actor(), $record);
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
     * @return array<string, string>
     */
    public static function types(): array
    {
        return collect(Group::TYPES)->mapWithKeys(fn (string $type): array => [$type => __('groups.types.'.$type)])->all();
    }

    /**
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return collect(GroupMember::ROLES)->mapWithKeys(fn (string $role): array => [$role => __('groups.roles.'.$role)])->all();
    }

    /**
     * @return list<Component>
     */
    public static function fields(): array
    {
        $mayLink = static::allows('groups.link.manage');

        return [
            TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
            Select::make('type')->label(__('groups.ui.type'))->options(static::types())->required()->default(Group::OPEN)
                ->helperText(__('groups.ui.type_hint')),
            Textarea::make('description')->label(__('groups.ui.description'))->rows(3)->maxLength(2000),
            Textarea::make('rules')->label(__('groups.ui.rules'))->rows(3)->maxLength(4000),
            Places::unit()->visible($mayLink)->helperText(__('groups.ui.link_hint')),
            Places::territory()->visible($mayLink),
            Select::make('project_id')->label(__('admin.projects.singular'))->visible($mayLink)->searchable()
                ->options(fn (): array => static::scoped(Project::query(), 'projects.read')->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Group $group = null): ?Group
    {
        $saved = null;
        static::attempt(function () use ($data, $group, &$saved): void {
            $saved = $group !== null
                ? app(ManageGroups::class)->update(static::actor(), $group, $data)
                : app(ManageGroups::class)->create(static::actor(), [...$data, 'name' => (string) ($data['name'] ?? '')]);
        }, __('admin.saved'));

        return $saved;
    }

    public static function table(Table $table): Table
    {
        $mine = fn (): array => app(GroupAccess::class)->groupIdsOf(static::actor()->person_id);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('members'))
            ->defaultSort('name')
            ->recordUrl(fn (Group $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->weight('bold')->searchable()->sortable()
                    ->description(fn (Group $record): ?string => str($record->description ?? '')->limit(90)->toString() ?: null),
                TextColumn::make('type')->label(__('groups.ui.type'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('groups.types.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        Group::OPEN => 'success', Group::CLOSED => 'warning', default => 'danger',
                    }),
                TextColumn::make('members_count')->label(__('groups.ui.members'))->alignEnd()->sortable(),
                TextColumn::make('my_role')->label(__('groups.ui.my_role'))
                    ->state(function (Group $record): ?string {
                        $role = app(GroupAccess::class)->roleOf($record, static::actor()->person_id);

                        return $role !== null ? __('groups.roles.'.$role) : null;
                    })->placeholder('—'),
                TextColumn::make('archived_at')->label(__('groups.ui.archived'))->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('mine')->label(__('groups.ui.mine'))->query(fn (Builder $query): Builder => $query->whereIn('groups.id', $mine())),
                SelectFilter::make('type')->label(__('groups.ui.type'))->options(static::types()),
            ])
            ->recordActions([
                Action::make('join')->label(fn (Group $record): string => __($record->type === Group::OPEN ? 'groups.ui.join' : 'groups.ui.request'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (Group $record): bool => static::allows('groups.join') && $record->archived_at === null
                        && ! app(GroupAccess::class)->isMember($record, static::actor()->person_id))
                    ->action(fn (Group $record) => static::attempt(
                        fn () => app(ManageGroups::class)->join(static::actor(), $record),
                        __($record->type === Group::OPEN ? 'groups.ui.joined' : 'groups.ui.request_sent'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'view' => ViewGroup::route('/{record}'),
        ];
    }
}
