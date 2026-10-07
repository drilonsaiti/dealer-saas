<?php

use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(TenantContext $context): void
    {
        Cache::put('job.tenant', $context->id());
        Cache::put('job.accounts', BankAccount::pluck('label')->all());
    }
}

it('runs queued jobs as the dealer that dispatched them', function () {
    config(['queue.default' => 'database', 'cache.default' => 'array']);

    $a = makeTenant();
    $b = makeTenant();
    asTenant($a, fn () => BankAccount::factory()->create(['label' => 'Konto A']));
    asTenant($b, fn () => BankAccount::factory()->create(['label' => 'Konto B']));

    // A statement (not an arrow fn's return value), so the job is pushed while the context is active.
    asTenant($a, function () {
        RecordTenantJob::dispatch();
    });

    expect(tenantContext()->hasTenant())->toBeFalse();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(Cache::get('job.tenant'))->toBe($a->id)
        ->and(Cache::get('job.accounts'))->toBe(['Konto A'])
        ->and(tenantContext()->hasTenant())->toBeFalse();
});
