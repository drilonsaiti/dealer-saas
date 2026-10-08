<?php

namespace App\Filament\App\Resources\StockCycles\Schemas;

use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Enums\PaymentStatus;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Ankauf: who sold the car, when, for how much, and its VAT situation.
 */
final class PurchaseForm
{
    /**
     * Form state for a new purchase; fillForm() replaces field defaults, so they live here.
     *
     * @return array<string, mixed>
     */
    public static function defaults(?int $mileage = null): array
    {
        return [
            'seller_kind' => SellerKind::Private->value,
            'purchase_type' => PurchaseType::Direct->value,
            'contract_on' => now()->toDateString(),
            'vat_situation' => VatSituation::PrivateNoVat->value,
            'payment_status' => PaymentStatus::Open->value,
            'mileage' => $mileage,
        ];
    }

    /**
     * @param  bool  $withMileage  false on "New vehicle", where the file's mileage field is used
     * @return list<mixed>
     */
    public static function components(bool $withMileage = true): array
    {
        return [
            Grid::make(3)->schema([
                PartySelect::make('seller_party_id', [PartyRole::Supplier, PartyRole::PrivateSeller, PartyRole::Auction])
                    ->label(__('Seller'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $seller = $state === null ? null : Party::query()->find($state);

                        if ($seller === null) {
                            return;
                        }

                        $kind = $seller->kind === PartyKind::Company ? SellerKind::Company : SellerKind::Private;
                        $set('seller_kind', $kind->value);
                        $set('vat_situation', $kind === SellerKind::Private ? VatSituation::PrivateNoVat->value : VatSituation::Unknown->value);
                    })
                    ->columnSpan(2),
                Select::make('seller_kind')->label(__('Seller is'))->options(SellerKind::class)->default(SellerKind::Private->value)->required(),
                Select::make('purchase_type')->label(__('Purchase type'))->options(PurchaseType::class)->default(PurchaseType::Direct->value)->required(),
                DatePicker::make('contract_on')->label(__('Purchase date'))->default(now())->maxDate(now()->addDay())->required(),
                DatePicker::make('delivered_on')->label(__('Taken over on')),
                MoneyInput::make('price_rp')->label(__('Purchase price'))->required(),
                Select::make('vat_situation')
                    ->label(__('VAT situation'))
                    ->options(VatSituation::class)
                    ->default(VatSituation::PrivateNoVat->value)
                    ->live()
                    ->required(),
                MoneyInput::make('vat_shown_rp')
                    ->label(__('VAT shown on the invoice'))
                    ->visible(fn (Get $get): bool => in_array($get('vat_situation'), [VatSituation::CompanyVatShown, VatSituation::CompanyVatShown->value, VatSituation::ForeignVat, VatSituation::ForeignVat->value], true)),
                TextInput::make('mileage')->label(__('Mileage at purchase'))->integer()->minValue(0)->suffix('km')->visible($withMileage),
                MoneyInput::make('payoff_rp')->label(__('Payoff (e.g. leasing balance)')),
                PartySelect::make('payoff_party_id', [PartyRole::FinancingPartner])
                    ->label(__('Paid off to'))
                    ->visible(fn (Get $get): bool => filled($get('payoff_rp'))),
                Select::make('payment_status')->label(__('Payment to seller'))->options(PaymentStatus::class)->default(PaymentStatus::Open->value)->required(),
            ]),
            Grid::make(2)->schema([
                Textarea::make('known_defects')->label(__('Known defects'))->rows(2),
                Textarea::make('agreed_deliverables')->label(__('Agreed with the seller'))->helperText(__('e.g. second key, service booklet, winter tyres'))->rows(2),
            ]),
        ];
    }
}
