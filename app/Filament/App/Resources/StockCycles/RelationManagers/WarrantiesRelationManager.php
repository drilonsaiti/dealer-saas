<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Warranty\Models\Warranty;
use App\Filament\App\Resources\Warranties\WarrantyResource;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Warranties sold with this car (added from "More → Add warranty"); claims on the warranty page.
 */
class WarrantiesRelationManager extends RelationManager
{
    protected static string $relationship = 'warranties';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Warranties');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product.provider'])->withCount('claims'))
            ->columns([
                TextColumn::make('product.name')->label(__('Product'))->description(fn (Warranty $record): string => $record->product->provider?->displayName() ?? __('own warranty')),
                TextColumn::make('policy_number')->label(__('Policy number'))->placeholder(__('not registered yet')),
                TextColumn::make('price_rp')->label(__('Price'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('cost_rp')->label(__('Premium'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('ends_on')->label(__('Until'))->date()->placeholder(__('at handover')),
                TextColumn::make('claims_count')->label(__('Claims')),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordActions([
                WarrantyResource::register(),
                WarrantyResource::remove(),
                Action::make('open')->label(__('Open'))->icon(Heroicon::OutlinedEye)->url(fn (Warranty $record): string => WarrantyResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
