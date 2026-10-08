<?php

namespace Database\Seeders;

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

        $corolla = $record(
            ['stammnummer' => '683737537', 'vin' => 'JTDKB20U403012345', 'make' => 'Toyota', 'model' => 'Corolla', 'variant' => '1.8 Hybrid', 'fuel' => 'hybrid', 'transmission' => 'automatic', 'body_type' => 'hatchback', 'first_registration_on' => '2020-06-19', 'power_kw' => 90, 'color_exterior' => 'Weiss'],
            ['mileage_in' => 79310, 'planned_price_rp' => 1_650_000, 'list_price_rp' => 1_890_000],
            StockCycleStatus::Purchased,
            now()->subDays(48)->toDateString(),
        );
        $transition($corolla, StockCycleStatus::Arrived, data: ['on' => now()->subDays(45)->toDateString()]);
        $transition($corolla, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(30)->toDateString()]);
        $transition($corolla, StockCycleStatus::Listed, data: ['on' => now()->subDays(29)->toDateString()]);

        $x3 = $record(
            ['stammnummer' => '412558903', 'make' => 'BMW', 'model' => 'X3', 'variant' => 'xDrive30i', 'internal_label' => 'BMW X3 Blau Shema', 'fuel' => 'petrol', 'transmission' => 'automatic', 'drive' => 'all_wheel', 'body_type' => 'suv', 'first_registration_on' => '2019-03-12', 'power_kw' => 185, 'color_exterior' => 'Blau'],
            ['mileage_in' => 98200, 'planned_price_rp' => 2_690_000],
            StockCycleStatus::Purchased,
            now()->subDays(95)->toDateString(),
        );
        $transition($x3, StockCycleStatus::Arrived);
        $transition($x3, StockCycleStatus::InPreparation);

        $record(
            ['stammnummer' => '653461306', 'make' => 'VW', 'model' => 'Golf', 'variant' => '2.0 TDI', 'fuel' => 'diesel', 'body_type' => 'hatchback'],
            ['mileage_in' => 121000, 'planned_price_rp' => 1_190_000],
        );

        $tucson = $record(
            ['stammnummer' => '507112840', 'make' => 'Hyundai', 'model' => 'Tucson', 'variant' => '1.6 T-GDi', 'fuel' => 'hybrid', 'body_type' => 'suv'],
            ['mileage_in' => 42000, 'list_price_rp' => 2_450_000],
            StockCycleStatus::Purchased,
            now()->subDays(120)->toDateString(),
        );
        $transition($tucson, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(100)->toDateString()]);
        $transition($tucson, StockCycleStatus::Listed, data: ['on' => now()->subDays(99)->toDateString()]);
        $transition($tucson, StockCycleStatus::Sold, data: ['on' => now()->subDays(50)->toDateString()]);
        $transition($tucson, StockCycleStatus::Delivered, data: ['on' => now()->subDays(45)->toDateString(), 'mileage_out' => 42150]);
    }
}
