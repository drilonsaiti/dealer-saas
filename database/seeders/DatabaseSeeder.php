<?php

namespace Database\Seeders;

use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\HandOverVehicle;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Actions\RecordVehicle;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local demo data: one platform admin and two dealers (to see tenant separation).
 * Password for all demo users: "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantContext $context, CreateTenant $createTenant): void
    {
        User::query()->firstOrCreate(
            ['email' => 'platform@example.ch'],
            ['name' => 'Platform Admin', 'password' => Hash::make('password'), 'is_platform_admin' => true, 'email_verified_at' => now()],
        );

        $demo = [
            ['name' => 'Demo Garage Bern', 'city' => 'Bern', 'default_locale' => 'de', 'admin' => 'admin@demo-bern.example.ch'],
            ['name' => 'Garage Démo Vevey', 'city' => 'Vevey', 'default_locale' => 'fr', 'admin' => 'admin@demo-vevey.example.ch'],
        ];

        foreach ($demo as $row) {
            if ($context->bypass(fn () => Tenant::query()->where('name', $row['name'])->exists())) {
                continue;
            }

            $tenant = $createTenant(
                ['name' => $row['name'], 'city' => $row['city'], 'default_locale' => $row['default_locale']],
                $row['admin'],
                'Admin '.$row['city'],
                sendInvitation: false,
            );

            User::query()->where('email', $row['admin'])->update([
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);

            $context->run($tenant, fn () => BankAccount::factory()->create(['label' => 'Hauptkonto']));

            if ($row['city'] === 'Bern') {
                $context->run($tenant, fn () => $this->demoVehicles());
            }
        }
    }

    /**
     * A few cars in different stages, so every screen has something to show.
     */
    private function demoVehicles(): void
    {
        $record = app(RecordVehicle::class);
        $transition = app(TransitionStockCycle::class);

        $reflex = Party::factory()->company('Reflex Automobiles Sàrl')->create(['street' => 'Avenue de Morges 12', 'email' => 'achat@reflex-auto.example.ch']);
        $privateSeller = Party::create(['kind' => 'person', 'roles' => ['private_seller'], 'first_name' => 'Marco', 'last_name' => 'Keller', 'zip' => '3006', 'city' => 'Bern', 'mobile' => '079 555 12 34']);
        $purchase = fn (Party $seller, string $sellerKind, int $price, int $daysAgo): array => [
            'seller_party_id' => $seller->id,
            'seller_kind' => $sellerKind,
            'contract_on' => now()->subDays($daysAgo)->toDateString(),
            'price_rp' => $price,
            'vat_situation' => $sellerKind === 'private' ? 'private_no_vat' : 'company_no_vat_shown',
        ];

        $corolla = $record(
            ['stammnummer' => '683737537', 'vin' => 'JTDKB20U403012345', 'make' => 'Toyota', 'model' => 'Corolla', 'variant' => '1.8 Hybrid', 'fuel' => 'hybrid', 'transmission' => 'automatic', 'body_type' => 'hatchback', 'first_registration_on' => '2020-06-19', 'power_kw' => 90, 'color_exterior' => 'Weiss'],
            ['mileage_in' => 79310, 'planned_price_rp' => 1_650_000, 'list_price_rp' => 1_890_000],
            $purchase($reflex, 'company', 1_520_000, 48),
        );
        $transition($corolla, StockCycleStatus::Arrived, data: ['on' => now()->subDays(45)->toDateString()]);
        $transition($corolla, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(30)->toDateString()]);
        $transition($corolla, StockCycleStatus::Listed, data: ['on' => now()->subDays(29)->toDateString()]);

        $x3 = $record(
            ['stammnummer' => '412558903', 'make' => 'BMW', 'model' => 'X3', 'variant' => 'xDrive30i', 'internal_label' => 'BMW X3 Blau Shema', 'fuel' => 'petrol', 'transmission' => 'automatic', 'drive' => 'all_wheel', 'body_type' => 'suv', 'first_registration_on' => '2019-03-12', 'power_kw' => 185, 'color_exterior' => 'Blau'],
            ['mileage_in' => 98200, 'planned_price_rp' => 2_690_000],
            $purchase($reflex, 'company', 2_350_000, 95),
        );
        $transition($x3, StockCycleStatus::Arrived);
        $transition($x3, StockCycleStatus::InPreparation);

        $category = fn (string $key): string => (string) CostCategory::query()->where('key', $key)->value('id');
        $recordCost = app(RecordCost::class);
        $transport = $recordCost(['stock_cycle_id' => $corolla->id, 'category_id' => $category('transport'), 'incurred_on' => now()->subDays(46)->toDateString(), 'description' => 'Transport Lausanne – Bern', 'gross_rp' => 35_000]);
        app(ConfirmCost::class)($transport);
        $recordCost(['stock_cycle_id' => $corolla->id, 'category_id' => $category('preparation'), 'incurred_on' => now()->subDays(32)->toDateString(), 'description' => 'Innen- und Aussenreinigung', 'gross_rp' => 28_000]);
        $recordCost(['stock_cycle_id' => $x3->id, 'category_id' => $category('repair'), 'incurred_on' => now()->subDays(5)->toDateString(), 'description' => 'Bremsen vorne (Offerte)', 'gross_rp' => 120_000, 'is_estimate' => true]);
        Commitment::create(['stock_cycle_id' => $corolla->id, 'description' => '4 neue Sommerreifen', 'estimated_cost_rp' => 64_000]);

        $record(
            ['stammnummer' => '653461306', 'make' => 'VW', 'model' => 'Golf', 'variant' => '2.0 TDI', 'fuel' => 'diesel', 'body_type' => 'hatchback'],
            ['mileage_in' => 121000, 'planned_price_rp' => 1_190_000],
        );

        $tucson = $record(
            ['stammnummer' => '507112840', 'make' => 'Hyundai', 'model' => 'Tucson', 'variant' => '1.6 T-GDi', 'fuel' => 'hybrid', 'body_type' => 'suv'],
            ['mileage_in' => 42000, 'list_price_rp' => 2_450_000],
            $purchase($privateSeller, 'private', 2_050_000, 120),
        );
        $transition($tucson, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(100)->toDateString()]);
        $transition($tucson, StockCycleStatus::Listed, data: ['on' => now()->subDays(99)->toDateString()]);
        $buyer = Party::create(['kind' => 'person', 'roles' => ['customer'], 'salutation' => 'ms', 'first_name' => 'Anna', 'last_name' => 'Meier', 'street' => 'Länggassstrasse 20', 'zip' => '3012', 'city' => 'Bern', 'email' => 'anna.meier@example.ch', 'mobile' => '079 222 33 44']);
        $sale = app(ContractSale::class)($tucson, [
            'buyer_party_id' => $buyer->id,
            'price_rp' => 2_450_000,
            'discount_rp' => 50_000,
            'payment_type' => 'bank',
            'sale_on' => now()->subDays(50)->toDateString(),
            'items' => [['kind' => 'warranty', 'description' => 'Garantie 12 Monate', 'qty' => 1, 'unit_price_rp' => 49_000]],
            'trade_in' => [
                'vehicle' => ['stammnummer' => '311908112', 'make' => 'VW', 'model' => 'Polo', 'variant' => '1.0 TSI'],
                'mileage' => 112_000,
                'value_rp' => 450_000,
            ],
        ]);
        app(HandOverVehicle::class)($sale, 42_150, now()->subDays(45)->toDateString());

        $octavia = $record(
            ['stammnummer' => '228461775', 'make' => 'Skoda', 'model' => 'Octavia', 'variant' => 'Combi 2.0 TDI 4x4', 'fuel' => 'diesel', 'drive' => 'all_wheel', 'body_type' => 'estate'],
            ['mileage_in' => 64_500, 'list_price_rp' => 2_290_000],
            $purchase($reflex, 'company', 1_880_000, 20),
        );
        $transition($octavia, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(12)->toDateString()]);
        $transition($octavia, StockCycleStatus::Listed, data: ['on' => now()->subDays(12)->toDateString()]);
        $customer = Party::create(['kind' => 'person', 'roles' => ['customer'], 'salutation' => 'mr', 'first_name' => 'Luca', 'last_name' => 'Rossi', 'zip' => '6900', 'city' => 'Lugano', 'locale' => 'it', 'mobile' => '076 111 22 33']);
        app(ReserveVehicle::class)($octavia, ['buyer_party_id' => $customer->id, 'price_rp' => 2_290_000, 'locale' => 'it', 'reserved_until' => now()->addDay()->toDateString()]);
    }
}
