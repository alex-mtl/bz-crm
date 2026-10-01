<?php

declare(strict_types=1);

namespace App\Filament\Resources\Moderation;

use App\Domain\People\Models\Person;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\ModerationReport;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Moderation;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Pages\SocialFeed;
use App\Filament\Resources\Moderation\Pages\ListReports;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The moderation queue (ФО §6.4.4): complaints about the content the moderator reaches — by the scope of a
 * system role or as a moderator of a group. Every decision goes through Moderation and is journaled.
 */
class ReportResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ModerationReport::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'moderation';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.social');
    }

    public static function getNavigationLabel(): string
    {
        return __('social.moderation.queue');
    }

    public static function getModelLabel(): string
    {
        return __('social.moderation.report');
    }

    public static function getPluralModelLabel(): string
    {
        return __('social.moderation.queue');
    }

    public static function getNavigationBadge(): ?string
    {
        // The counter is the same scoped query as the list.
        $open = app(Moderation::class)->queueFor(static::actor())->where('status', ModerationReport::OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('moderation_reports.id', app(Moderation::class)->queueFor(static::actor())->select('moderation_reports.id'));
    }

    public static function canViewAny(): bool
    {
        return app(Moderation::class)->hasQueue(static::actor());
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

    public static function target(ModerationReport $report): Post|Comment
    {
        return app(Moderation::class)->targetOf($report);
    }

    /**
     * @return list<Component>
     */
    public static function reasonField(): array
    {
        return [Textarea::make('reason')->label(__('social.ui.reason'))->rows(2)->required()->maxLength(500)];
    }

    /**
     * Warning and muting — shared by the queue and by the list of sanctions.
     *
     * @param  callable(Model): ?Person  $person
     * @return list<Action>
     */
    public static function sanctions(callable $person): array
    {
        return [
            Action::make('warn')->label(__('social.moderation.warn'))->icon('heroicon-o-exclamation-triangle')->color('warning')
                ->visible(fn (Model $record): bool => $person($record) !== null && static::allows('moderation.warn', $person($record)))
                ->schema(static::reasonField())
                ->action(fn (Model $record, array $data) => static::attempt(
                    fn () => app(Moderation::class)->warn(static::actor(), $person($record) ?? new Person, (string) $data['reason']),
                    __('social.moderation.warned'),
                )),
            Action::make('mute')->label(__('social.moderation.mute'))->icon('heroicon-o-speaker-x-mark')->color('danger')
                ->visible(fn (Model $record): bool => $person($record) !== null && static::allows('moderation.mute', $person($record)))
                ->schema([
                    DateTimePicker::make('until')->label(__('social.moderation.mute_until'))->seconds(false)->required()->default(fn (): Carbon => now()->addDay()),
                    ...static::reasonField(),
                ])
                ->action(fn (Model $record, array $data) => static::attempt(
                    fn () => app(Moderation::class)->mute(static::actor(), $person($record) ?? new Person, Carbon::parse($data['until']), (string) $data['reason']),
                    __('social.moderation.muted'),
                )),
        ];
    }

    public static function table(Table $table): Table
    {
        $m = fn (string $key): string => __('social.moderation.'.$key);
        $author = fn (Model $record): ?Person => $record instanceof ModerationReport ? static::target($record)->author : null;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('reporter'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('content')->label($m('content'))->wrap()
                    ->state(fn (ModerationReport $record): string => str(static::target($record)->body ?? '')->limit(140)->toString())
                    ->description(fn (ModerationReport $record): string => $m('kinds.'.$record->reportable_type).' · '.static::target($record)->author->fullName()),
                TextColumn::make('reason_code')->label(__('social.ui.reason'))
                    ->formatStateUsing(fn (string $state): string => Options::catalog('report_reasons')[$state] ?? $state)
                    ->description(fn (ModerationReport $record): ?string => $record->comment),
                TextColumn::make('reporter')->label($m('reporter'))->state(fn (ModerationReport $record): string => $record->reporter->fullName()),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $m('statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        ModerationReport::OPEN => 'warning', ModerationReport::UPHELD => 'danger', default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->default(ModerationReport::OPEN)
                    ->options(collect([ModerationReport::OPEN, ModerationReport::UPHELD, ModerationReport::DISMISSED])
                        ->mapWithKeys(fn (string $status): array => [$status => $m('statuses.'.$status)])->all()),
            ])
            ->recordActions([
                Action::make('open')->label($m('open'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(function (ModerationReport $record): string {
                        $target = static::target($record);

                        return SocialFeed::getUrl(['post' => $target instanceof Post ? $target->id : $target->post_id]);
                    }),
                ActionGroup::make([
                    Action::make('hide')->label(__('social.ui.hide'))->icon('heroicon-o-eye-slash')->color('danger')
                        ->visible(fn (ModerationReport $record): bool => ! static::target($record)->isHidden()
                            && app(Moderation::class)->mayModerate(static::actor(), static::target($record)))
                        ->schema(static::reasonField())
                        ->action(fn (ModerationReport $record, array $data) => static::attempt(
                            fn () => app(Moderation::class)->hide(static::actor(), static::target($record), (string) $data['reason']),
                            __('social.ui.hidden_done'),
                        )),
                    Action::make('restore')->label(__('social.ui.restore'))->icon('heroicon-o-eye')
                        ->visible(fn (ModerationReport $record): bool => static::target($record)->isHidden()
                            && app(Moderation::class)->mayModerate(static::actor(), static::target($record)))
                        ->requiresConfirmation()
                        ->action(fn (ModerationReport $record) => static::attempt(
                            fn () => app(Moderation::class)->restore(static::actor(), static::target($record)),
                            __('social.ui.restored'),
                        )),
                    Action::make('dismiss')->label($m('dismiss'))->icon('heroicon-o-x-mark')
                        ->visible(fn (ModerationReport $record): bool => $record->status === ModerationReport::OPEN)
                        ->requiresConfirmation()
                        ->action(fn (ModerationReport $record) => static::attempt(
                            fn () => app(Moderation::class)->dismiss(static::actor(), $record),
                            $m('dismissed'),
                        )),
                    ...static::sanctions($author),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
