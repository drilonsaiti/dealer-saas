<?php

use App\Domain\Operations\Models\RestoreDrill;
use App\Filament\Platform\Resources\RestoreDrills\Pages\ListRestoreDrills;
use App\Filament\Platform\Resources\RestoreDrills\RestoreDrillResource;
use App\Filament\Platform\Widgets\RestoreDrillStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Acceptance test 13: a monthly restore drill is recorded, and a missing one is noticed.
 */

beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow('2026-10-08 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('records a restore drill from the drill script', function () {
    $this->artisan('backup:record-drill', [
        'status' => 'ok',
        '--file' => 'dealer-20261001.dump',
        '--tenants' => '3',
        '--audit-rows' => '1520',
        '--rls-tables' => '26',
        '--duration' => '41',
    ])->assertSuccessful();

    $drill = RestoreDrill::sole();

    expect($drill->status)->toBe(RestoreDrill::STATUS_OK)
        ->and($drill->backup_file)->toBe('dealer-20261001.dump')
        ->and($drill->tenants)->toBe(3)
        ->and($drill->rls_tables)->toBe(26)
        ->and($drill->duration_seconds)->toBe(41)
        ->and(RestoreDrill::isOverdue())->toBeFalse();
});

it('refuses an unknown drill status', function () {
    $this->artisan('backup:record-drill', ['status' => 'maybe'])->assertFailed();

    expect(RestoreDrill::count())->toBe(0);
});

it('is overdue without a successful drill in the last 35 days', function () {
    expect(RestoreDrill::isOverdue())->toBeTrue();

    RestoreDrill::create(['status' => RestoreDrill::STATUS_OK, 'ran_at' => now()->subDays(36)]);
    RestoreDrill::create(['status' => RestoreDrill::STATUS_FAILED, 'ran_at' => now()->subDay(), 'message' => 'Restored database is incomplete']);

    expect(RestoreDrill::isOverdue())->toBeTrue();

    RestoreDrill::create(['status' => RestoreDrill::STATUS_OK, 'ran_at' => now()->subDays(3)]);

    expect(RestoreDrill::isOverdue())->toBeFalse();
});

it('shows the drill log and warns on the platform when a drill is overdue', function () {
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel('platform');

    $old = RestoreDrill::create(['status' => RestoreDrill::STATUS_OK, 'ran_at' => now()->subDays(40), 'tenants' => 2]);
    $failed = RestoreDrill::create(['status' => RestoreDrill::STATUS_FAILED, 'ran_at' => now()->subDay(), 'message' => 'Restore drill aborted (line 26)']);

    Livewire::test(ListRestoreDrills::class)
        ->assertCanSeeTableRecords([$failed, $old], inOrder: true)
        ->assertSee('Restore drill aborted (line 26)');

    expect(RestoreDrillResource::getNavigationBadge())->toBe('Overdue');

    Livewire::withoutLazyLoading();
    Livewire::test(RestoreDrillStatus::class)
        ->assertSee('Overdue: run deploy/restore-test.sh (due every month).')
        ->assertSee('Failed');
});

it('keeps the drill log out of the dealer app', function () {
    expect(class_exists(App\Filament\App\Resources\RestoreDrills\RestoreDrillResource::class))->toBeFalse()
        ->and(Schema::hasColumn('restore_drills', 'tenant_id'))->toBeFalse();
});
