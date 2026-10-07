<?php

namespace Database\Seeders;

use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
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
        }
    }
}
