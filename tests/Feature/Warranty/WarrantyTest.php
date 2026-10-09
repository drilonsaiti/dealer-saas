<?php

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\HandOverVehicle;
use App\Domain\Sales\Enums\SaleItemKind;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Warranty\Actions\ActivateWarranties;
use App\Domain\Warranty\Actions\AddWarranty;
use App\Domain\Warranty\Actions\HandleWarrantyClaim;
use App\Domain\Warranty\Actions\RegisterWarranty;
use App\Domain\Warranty\Enums\ClaimStatus;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;

/*
 * Acceptance test 8: a warranty sold with the car (SuisseFox FoxG3, 12 months / 20'000 km)
 * and a warranty claim whose dealer share lands on the original vehicle file.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    Carbon::setTestNow('2026-10-09 10:00');
    $this->tenant = makeDealer(['slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->actingAs(makeMember($this->tenant, Role::Administrator));
    asTenant($this->tenant, fn () => BankAccount::factory()->create(['is_default' => true]));
});

afterEach(fn () => Carbon::setTestNow());

function foxG3(): WarrantyProduct
{
    $suisseFox = Party::factory()->create(['kind' => 'company', 'company_name' => 'SuisseFox AG', 'first_name' => null, 'last_name' => null]);

    return WarrantyProduct::create([
        'provider_party_id' => $suisseFox->id,
        'name' => ['de' => 'FoxG3', 'fr' => 'FoxG3', 'it' => 'FoxG3', 'en' => 'FoxG3'],
        'duration_months' => 12,
        'km_limit' => 20_000,
        'coverage_limit_rp' => 1_000_000,
        'deductible_rp' => 20_000,
        'cost_rp' => 39_000,
        'price_rp' => 69_000,
    ]);
}

it('sells a warranty, activates it at handover and settles a claim on the original file (acceptance test 8)', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $cycle = $sale->stockCycle;
        $purchaseBefore = app(CalculateMargin::class)($cycle);

        // On sale: price into the sale (contract and invoice), premium into the costs.
        $warranty = app(AddWarranty::class)($sale, foxG3());
        $sale->refresh()->load('items');

        expect($warranty->status)->toBe(WarrantyStatus::Draft)
            ->and($sale->items->firstWhere('kind', SaleItemKind::Warranty)->unit_price_rp)->toBe(69_000)
            ->and($sale->totalRp())->toBe(2_690_000 + 80_000 + 69_000)
            ->and($warranty->cost->gross_rp)->toBe(39_000)
            ->and($warranty->cost->is_estimate)->toBeTrue()
            ->and(app(CalculateMargin::class)($cycle)->marginRp())->toBe($purchaseBefore->marginRp() + 69_000 - 39_000);

        // Policy registered with its certificate; a later coverage change is a new version.
        [$pdf, $name] = explode('|', fakeFile('%PDF-1.4 Police FoxG3', 'police.pdf'));
        app(RegisterWarranty::class)($warranty, 'FX-2026-77881', $pdf, $name);
        [$pdf2, $name2] = explode('|', fakeFile('%PDF-1.4 Police FoxG3 v2', 'police-neu.pdf'));
        app(RegisterWarranty::class)($warranty->refresh(), 'FX-2026-77881', $pdf2, $name2, ['coverage_limit_rp' => 1_500_000]);
        $warranty->refresh();

        expect($warranty->cost->status)->toBe(CostStatus::Confirmed)
            ->and($warranty->coverage_limit_rp)->toBe(1_500_000)
            ->and($warranty->certificate->versions()->count())->toBe(2)
            ->and($warranty->certificate->category->key)->toBe('warranty_policy');

        // The final invoice carries the warranty; paid → handover → warranty active.
        app(ContractSale::class)($cycle, []);
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale->refresh(), InvoiceType::Final));
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-10-10', 'amount_rp' => $invoice->total_rp, 'method' => 'bank'], [[$invoice, $invoice->total_rp]]);
        $keys = app(SyncChecklist::class)->handover($sale)->items->firstWhere('key', 'keys');
        app(HandOverVehicle::class)($sale->refresh(), 80_000, '2026-10-12', [$keys->id]);
        $warranty->refresh();

        expect($invoice->lines->pluck('description'))->toContain('FoxG3')
            ->and($warranty->status)->toBe(WarrantyStatus::Active)
            ->and($warranty->starts_on->toDateString())->toBe('2026-10-12')
            ->and($warranty->ends_on->toDateString())->toBe('2027-10-11')
            ->and($warranty->km_at_start)->toBe(80_000)
            ->and($warranty->kmUntil())->toBe(100_000)
            ->and(fn () => app(AddWarranty::class)->remove($warranty))->toThrow(BusinessRuleException::class, 'not active yet');

        // Claim: gearbox, CHF 2'400; customer CHF 200 deductible, SuisseFox CHF 1'800, dealer CHF 400.
        $cycle->forceFill(['status' => StockCycleStatus::Archived])->save();
        $workshop = Party::factory()->create(['kind' => 'company', 'company_name' => 'Garage Muster AG', 'first_name' => null, 'last_name' => null]);
        $claim = app(HandleWarrantyClaim::class)->report($warranty, ['occurred_on' => '2027-03-02', 'mileage' => 91_500, 'description' => 'Getriebe schaltet nicht mehr in den 3. Gang', 'workshop_party_id' => $workshop->id]);

        expect(app(HandleWarrantyClaim::class)->outsideCover($claim))->toBe([])
            ->and(fn () => app(HandleWarrantyClaim::class)->decide($claim, ['amount_rp' => 240_000, 'deductible_rp' => 20_000, 'provider_share_rp' => 180_000, 'dealer_share_rp' => 30_000]))
            ->toThrow(BusinessRuleException::class, 'add up');

        app(HandleWarrantyClaim::class)->decide($claim, ['amount_rp' => 240_000, 'deductible_rp' => 20_000, 'provider_share_rp' => 180_000, 'dealer_share_rp' => 40_000], 'Synchronring defekt');
        $settled = app(HandleWarrantyClaim::class)->settle($claim->refresh(), '2027-03-20');
        $cost = Cost::query()->findOrFail($settled->cost_id);

        expect($settled->status)->toBe(ClaimStatus::Closed)
            ->and($cost->stock_cycle_id)->toBe($cycle->id) // the original file, even though archived
            ->and($cost->gross_rp)->toBe(40_000)
            ->and($cost->status)->toBe(CostStatus::Confirmed)
            ->and(app(CalculateMargin::class)($cycle->refresh())->confirmedCostsRp)->toBe(39_000 + 40_000);
    });
});

it('flags a claim outside the cover and expires warranties after their end date', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $warranty = app(AddWarranty::class)($sale, foxG3());
        app(ActivateWarranties::class)($sale, Carbon::parse('2025-06-01'), 50_000);
        $claim = app(HandleWarrantyClaim::class)->report($warranty->refresh(), ['occurred_on' => '2026-07-01', 'mileage' => 75_000, 'description' => 'Turbo']);

        expect(app(HandleWarrantyClaim::class)->outsideCover($claim))->toHaveCount(2)
            ->and(app(ActivateWarranties::class)->expire())->toBe(1)
            ->and($warranty->refresh()->status)->toBe(WarrantyStatus::Expired);
    });
});

it('removes a draft warranty with its price and premium, and refuses one after the final invoice', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $warranty = app(AddWarranty::class)($sale, foxG3());
        app(AddWarranty::class)->remove($warranty);

        expect($warranty->refresh()->status)->toBe(WarrantyStatus::Cancelled)
            ->and($sale->items()->where('kind', 'warranty')->count())->toBe(0)
            ->and(Cost::query()->count())->toBe(0);

        app(ContractSale::class)($sale->stockCycle, []);
        app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale->refresh(), InvoiceType::Final));

        expect(fn () => app(AddWarranty::class)($sale->refresh(), foxG3()))->toThrow(BusinessRuleException::class, 'Credit it first');
    });
});

it('lists warranties ending within 30 days', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        app(AddWarranty::class)($sale, foxG3());
        app(ActivateWarranties::class)($sale, Carbon::parse('2025-10-25'), 50_000);

        expect(Warranty::query()->expiringWithin(30)->count())->toBe(1);
    });
});
