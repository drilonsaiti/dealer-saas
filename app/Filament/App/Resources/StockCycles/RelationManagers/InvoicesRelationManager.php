<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoices and credit notes of this vehicle file, with what is still open.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Invoices');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', Invoice::class) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('recipient'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->placeholder(__('Draft'))->fontFamily('mono'),
                TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (InvoiceType $state): string => $state->getLabel()),
                TextColumn::make('recipient.last_name')->label(__('Invoice to'))->state(fn (Invoice $record): string => $record->recipient->displayName()),
                TextColumn::make('issued_on')->label(__('Date'))->date()->placeholder('–'),
                TextColumn::make('total_rp')->label(__('Total'))->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd(),
                TextColumn::make('open')->label(__('Open'))->state(fn (Invoice $record): ?string => $record->type === InvoiceType::CreditNote ? null : Money::format($record->openRp()))->alignEnd()->placeholder('–'),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('open')->label(__('Open'))->icon(Heroicon::OutlinedEye)->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
