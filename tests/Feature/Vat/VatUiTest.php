<?php

use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\SaveInvoiceDraft;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Parties\Models\Party;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatCode;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Filament\App\Resources\VatPeriods\Pages\ListVatPeriods;
use App\Filament\App\Resources\VatPeriods\Pages\ViewVatPeriod;
use App\Filament\App\Resources\VatPeriods\RelationManagers\TaxEventsRelationManager;
use App\Filament\App\Resources\VatProfiles\Pages\ManageVatProfiles;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    config(['dealer.vat.ech0217_xsd' => null]); // the real schema is checked in Ech0217StructureTest
    Carbon::setTestNow('2026-03-16 10:00');
    $this->tenant = makeDealer(['slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'uid' => 'CHE-404.944.758', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->accounting = makeMember($this->tenant, Role::Accounting);
    asTenant($this->tenant, fn () => BankAccount::factory()->create(['is_default' => true]));
});

afterEach(fn () => Carbon::setTestNow());

function uiInvoice(int $grossRp, string $code = InstallDefaultVatCodes::TAXABLE_NORMAL): void
{
    app(IssueInvoice::class)(app(SaveInvoiceDraft::class)(null, ['type' => InvoiceType::Standard, 'recipient_party_id' => Party::factory()->create()->id], [
        ['description' => 'Fahrzeug', 'unit_price_rp' => $grossRp, 'vat_code_id' => VatCode::byKey($code)->id],
    ]));
}

it('sets up the VAT settings and picks up invoices issued before', function () {
    useAppPanel($this->tenant, $this->accounting);
    uiInvoice(1_000_000);
    Repeater::fake();

    Livewire::test(ManageVatProfiles::class)
        ->callAction('create', data: [
            'valid_from' => '2026-01-01',
            'liable' => true,
            'method' => 'net_tax_rate',
            'basis' => 'agreed',
            'period' => 'half_year',
            'rates' => [['activity' => 'Autohandel', 'activity_code' => '11111', 'rate' => '0.6']],
        ])
        ->assertHasNoActionErrors();

    $profile = VatProfile::query()->sole();

    expect($profile->netTaxRates->sole()->rate)->toBe('0.6000')
        ->and($profile->netTaxRates->sole()->getTranslation('activity', 'fr'))->toBe('Autohandel');

    Livewire::test(ListVatPeriods::class)
        ->callAction('collect')
        ->assertNotified();

    expect(TaxEvent::query()->sole()->state)->toBe(TaxEventState::Auto);

    Livewire::test(ListVatPeriods::class)->assertCountTableRecords(1)->assertSee('60.00');
});

it('confirms an entry, closes the period and exports the XML', function () {
    useAppPanel($this->tenant, $this->accounting);
    app(SaveVatProfile::class)(null, ['valid_from' => '2026-01-01', 'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year'], [['activity' => 'Autohandel', 'activity_code' => '11111', 'rate' => '0.6']]);
    uiInvoice(1_000_000);
    uiInvoice(500_000, InstallDefaultVatCodes::NO_TAX_SHOWN);
    Carbon::setTestNow('2026-07-10');
    $period = VatPeriod::query()->sole();

    Livewire::test(ViewVatPeriod::class, ['record' => $period->getRouteKey()])
        ->assertSee('Preview, not complete')
        ->assertActionDisabled('close');

    Livewire::test(TaxEventsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewVatPeriod::class])
        ->callTableAction('confirm', TaxEvent::query()->where('state', 'confirm')->sole())
        ->assertHasNoTableActionErrors();

    Livewire::test(ViewVatPeriod::class, ['record' => $period->getRouteKey()])
        ->assertSee('ready to close')
        ->callAction('close')
        ->assertHasNoActionErrors()
        ->callAction('export')
        ->assertHasNoActionErrors();

    expect($period->refresh()->status)->toBe(VatPeriodStatus::Exported)
        ->and($period->figures['payable_rp'])->toBe(9_000);

    Livewire::test(ViewVatPeriod::class, ['record' => $period->getRouteKey()])
        ->callAction('submitted', data: ['submitted_on' => '2026-07-10', 'reference' => 'ESTV-1'])
        ->assertHasNoActionErrors()
        ->callAction('paid', data: ['paid_on' => '2026-07-10'])
        ->assertHasNoActionErrors();

    expect($period->refresh()->status)->toBe(VatPeriodStatus::Paid);
});

it('keeps VAT away from sales and lets read-only users look without closing', function () {
    $sales = makeMember($this->tenant, Role::Sales);
    $readOnly = makeMember($this->tenant, Role::ReadOnly);

    asTenant($this->tenant, function () use ($sales, $readOnly) {
        $period = VatPeriod::query()->create(['vat_profile_id' => app(SaveVatProfile::class)(null, ['valid_from' => '2026-01-01', 'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year'], [['activity' => 'Autohandel', 'rate' => '0.6']])->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);

        expect($sales->can('viewAny', VatPeriod::class))->toBeFalse()
            ->and($readOnly->can('view', $period))->toBeTrue()
            ->and($readOnly->can('close', $period))->toBeFalse()
            ->and($readOnly->can('create', VatProfile::class))->toBeFalse()
            ->and($this->accounting->can('close', $period))->toBeTrue();
    });
});
