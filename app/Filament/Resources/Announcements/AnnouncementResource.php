<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements;

use App\Domain\CRM\SegmentQuery;
use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementReceipt;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Announcements (ФО §6.13): what the user sent — and, for those who may send critical notices, every critical
 * one with the list of who confirmed reading it.
 */
class AnnouncementResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Announcement::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'announcements';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.notifications');
    }

    public static function getModelLabel(): string
    {
        return __('notifications.ui.announcement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.ui.announcements');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('announcements.id', app(Announcements::class)->sentVisibleTo(static::actor())->select('announcements.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('notifications.broadcast') || static::allows('notifications.critical.send');
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
        $n = fn (string $key): string => __('notifications.ui.'.$key);

        return [
            TextInput::make('title')->label($n('title'))->required()->maxLength(255),
            Textarea::make('body')->label($n('body'))->rows(4)->required()->maxLength(5000),
            Toggle::make('critical')->label($n('critical'))->helperText($n('critical_hint'))
                ->visible(fn (): bool => static::allows('notifications.critical.send'))
                // Whoever holds only the critical right sends only critical notices.
                ->default(fn (): bool => ! static::allows('notifications.broadcast'))
                ->disabled(fn (): bool => ! static::allows('notifications.broadcast')),
            Places::unit('unit_id')->helperText($n('audience_hint')),
            Places::territory(),
            Select::make('person_types')->label(__('admin.fields.person_type'))->multiple()->options(fn (): array => Options::catalog('person_types')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function send(array $data): void
    {
        static::attempt(function () use ($data): void {
            $segments = app(SegmentQuery::class);
            $criteria = $segments->clean(array_intersect_key($data, array_flip(['unit_id', 'territory_id', 'person_types'])));
            /** @var Builder<Person> $people */
            $people = $segments->build($criteria);
            app(Announcements::class)->send(static::actor(), [
                'title' => (string) $data['title'], 'body' => (string) $data['body'],
                'critical' => (bool) ($data['critical'] ?? ! static::allows('notifications.broadcast')),
                'audience' => $criteria,
            ], $people);
        }, __('notifications.ui.sent'));
    }

    public static function table(Table $table): Table
    {
        $n = fn (string $key): string => __('notifications.ui.'.$key);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('sender.person')
                ->withCount(['receipts as acknowledged_count' => fn (Builder $q) => $q->whereNotNull('acknowledged_at')]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                IconColumn::make('is_critical')->label($n('critical'))->boolean()->trueIcon('heroicon-o-exclamation-triangle')->trueColor('danger')->falseIcon(''),
                TextColumn::make('title')->label($n('title'))->weight('bold')->wrap()->searchable()
                    ->description(fn (Announcement $record): string => str($record->body)->limit(120)->toString()),
                TextColumn::make('sender')->label($n('sender'))->state(fn (Announcement $record): string => $record->sender->person->fullName()),
                TextColumn::make('recipients')->label($n('recipients'))->alignEnd(),
                TextColumn::make('acknowledged_count')->label($n('acknowledged_count'))->alignEnd()
                    ->state(fn (Announcement $record): ?string => $record->is_critical ? $record->getAttribute('acknowledged_count').' / '.$record->recipients : null)
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('receipts')->label($n('receipts'))->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (Announcement $record): bool => $record->is_critical && app(Announcements::class)->maySeeReceipts(static::actor(), $record))
                    ->modalHeading(fn (Announcement $record): string => $record->title)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('social.ui.close'))
                    ->modalContent(fn (Announcement $record): View => view('filament.notifications.receipts', [
                        'receipts' => AnnouncementReceipt::query()->with('user.person')->where('announcement_id', $record->id)
                            ->orderByRaw('acknowledged_at is null desc')->orderBy('acknowledged_at')->get(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAnnouncements::route('/')];
    }
}
