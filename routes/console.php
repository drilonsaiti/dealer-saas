<?php

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Actions\ArchiveDeliveredCycles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Commands that touch business data run once per dealer, inside that dealer's tenant context,
 * so Row-Level Security applies to them exactly as it does to a web request.
 */
Artisan::command('vehicles:archive', function (TenantContext $context, ArchiveDeliveredCycles $archive): void {
    $tenants = $context->bypass(fn () => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get());

    foreach ($tenants as $tenant) {
        $count = $context->run($tenant, fn (): int => $archive());

        if ($count > 0) {
            $this->info("{$tenant->name}: {$count} vehicle file(s) archived");
        }
    }
})->purpose('Archive vehicle files delivered longer ago than the dealer\'s archive period');

Schedule::command('vehicles:archive')->dailyAt('02:45');
