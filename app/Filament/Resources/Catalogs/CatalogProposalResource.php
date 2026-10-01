<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs;

use App\Domain\Catalogs\Actions\ReviewCatalogProposal;
use App\Domain\Catalogs\Enums\ProposalStatus;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Catalogs\Models\CatalogItemProposal;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Catalogs\Pages\ListCatalogProposals;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Review queue for proposed catalog items (Д-16), with the similar existing items shown to the reviewer.
 */
class CatalogProposalResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = CatalogItemProposal::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'catalog-proposals';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.catalogs');
    }

    public static function getModelLabel(): string
    {
        return __('admin.proposals.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.proposals.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CatalogItemProposal::query()->where('status', ProposalStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return static::allows('catalogs.review');
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

    protected static function similarNames(CatalogItemProposal $proposal): string
    {
        $ids = $proposal->similar_item_ids ?? [];

        return $ids === [] ? '—' : CatalogItem::query()->whereKey($ids)->get()->map(fn (CatalogItem $i): string => $i->name())->join(', ');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('catalog_code')->label(__('admin.catalogs.catalog'))->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogItemResource::catalogOptions()[$state] ?? $state),
                TextColumn::make('names')->label(__('admin.fields.name'))
                    ->state(fn (CatalogItemProposal $record): string => $record->names[$record->source_locale] ?? (string) collect($record->names)->first()),
                TextColumn::make('justification')->label(__('admin.catalogs.justification'))->wrap()->limit(120),
                TextColumn::make('similar')->label(__('admin.proposals.similar'))
                    ->state(fn (CatalogItemProposal $record): string => static::similarNames($record))
                    ->color(fn (string $state): string => $state === '—' ? 'gray' : 'warning'),
                TextColumn::make('proposed_by')->label(__('admin.proposals.proposed_by'))
                    ->state(fn (CatalogItemProposal $record): string => User::query()->with('person')->find($record->proposed_by_user_id)?->person->fullName() ?? '—'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (ProposalStatus $state): string => __('catalogs.proposal_statuses.'.$state->value)),
                TextColumn::make('review_comment')->label(__('admin.proposals.comment'))->toggleable(isToggledHiddenByDefault: true)->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))
                    ->options(collect(ProposalStatus::cases())->mapWithKeys(fn (ProposalStatus $s): array => [$s->value => __('catalogs.proposal_statuses.'.$s->value)])->all())
                    ->default(ProposalStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('admin.proposals.approve'))
                    ->color('success')
                    ->visible(fn (CatalogItemProposal $record): bool => $record->status === ProposalStatus::Pending)
                    ->fillForm(fn (CatalogItemProposal $record): array => $record->names)
                    ->schema(fn (CatalogItemProposal $record): array => [
                        Text::make(__('admin.proposals.similar').': '.static::similarNames($record)),
                        TextInput::make('ro')->label('ro')->maxLength(150),
                        TextInput::make('ru')->label('ru')->maxLength(150),
                        TextInput::make('en')->label('en')->maxLength(150),
                        Textarea::make('comment')->label(__('admin.proposals.comment'))->maxLength(1000),
                    ])
                    ->action(fn (CatalogItemProposal $record, array $data) => static::attempt(
                        fn () => app(ReviewCatalogProposal::class)(
                            static::actor(), $record, true, $data['comment'] ?? null,
                            array_filter(['ro' => $data['ro'] ?? null, 'ru' => $data['ru'] ?? null, 'en' => $data['en'] ?? null], 'filled'),
                        ),
                        __('admin.proposals.approved'),
                    )),
                Action::make('reject')
                    ->label(__('admin.proposals.reject'))
                    ->color('danger')
                    ->visible(fn (CatalogItemProposal $record): bool => $record->status === ProposalStatus::Pending)
                    ->schema([Textarea::make('comment')->label(__('admin.proposals.comment'))->required()->maxLength(1000)])
                    ->action(fn (CatalogItemProposal $record, array $data) => static::attempt(
                        fn () => app(ReviewCatalogProposal::class)(static::actor(), $record, false, (string) $data['comment']),
                        __('admin.proposals.rejected'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogProposals::route('/'),
        ];
    }
}
