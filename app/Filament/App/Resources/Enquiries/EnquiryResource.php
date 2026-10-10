<?php

namespace App\Filament\App\Resources\Enquiries;

use App\Domain\Listings\Enums\EnquiryStatus;
use App\Domain\Listings\Models\Enquiry;
use App\Filament\App\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\App\Resources\Parties\PartyResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enquiries from the website (and the API): who, about which car, what; handled by whom.
 *
 * @extends resource<Enquiry>
 */
class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 14;

    public static function getModelLabel(): string
    {
        return __('Enquiry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Enquiries');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Enquiry::query()->where('status', EnquiryStatus::New->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('name')->label(__('Name'))
                    ->url(fn (Enquiry $record): ?string => $record->party === null ? null : PartyResource::getUrl('edit', ['record' => $record->party])),
                TextEntry::make('email')->label(__('E-mail'))->copyable()->placeholder('–'),
                TextEntry::make('phone')->label(__('Phone'))->copyable()->placeholder('–'),
                TextEntry::make('stockCycle')->label(__('Vehicle'))
                    ->state(fn (Enquiry $record): ?string => $record->stockCycle?->title())
                    ->url(fn (Enquiry $record): ?string => $record->stockCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->stockCycle]))
                    ->placeholder(__('general enquiry')),
                TextEntry::make('created_at')->label(__('Received'))->dateTime(),
                TextEntry::make('status')->label(__('Status'))->badge(),
            ]),
            TextEntry::make('message')->label(__('Message'))->columnSpanFull()->extraAttributes(['style' => 'white-space:pre-line']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['stockCycle.vehicle', 'party', 'handler']))
            ->columns([
                TextColumn::make('created_at')->label(__('Received'))->since()->sortable()
                    ->description(fn (Enquiry $record): string => $record->created_at->format('d.m.Y H:i')),
                TextColumn::make('name')->label(__('Name'))->searchable()
                    ->description(fn (Enquiry $record): string => implode(' · ', array_filter([$record->email, $record->phone]))),
                TextColumn::make('stockCycle')->label(__('Vehicle'))->state(fn (Enquiry $record): ?string => $record->stockCycle?->vehicle->displayName())->placeholder(__('general enquiry')),
                TextColumn::make('message')->label(__('Message'))->limit(80)->wrap(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->description(fn (Enquiry $record): ?string => $record->handler?->name),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                self::setStatus('start', EnquiryStatus::InProgress, __('I take it'), Heroicon::OutlinedHandRaised),
                self::setStatus('close', EnquiryStatus::Closed, __('Close'), Heroicon::OutlinedCheck),
            ]);
    }

    private static function setStatus(string $name, EnquiryStatus $status, string $label, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (Enquiry $record): bool => $record->status !== $status && $record->status !== EnquiryStatus::Closed && (auth()->user()?->can('update', $record) ?? false))
            ->action(fn (Enquiry $record) => $record->forceFill(['status' => $status, 'handled_by' => auth()->id(), 'handled_at' => now()])->save());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnquiries::route('/'),
        ];
    }
}
