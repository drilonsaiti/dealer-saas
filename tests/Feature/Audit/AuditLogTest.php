<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = makeTenant();
    $this->user = makeMember($this->tenant, Role::Accounting);
    $this->actingAs($this->user);
});

it('records who changed what, with old and new values', function () {
    asTenant($this->tenant, function () {
        $account = BankAccount::factory()->create(['label' => 'Alt']);
        $account->update(['label' => 'Neu']);

        $log = AuditLog::where('auditable_id', $account->id)->where('event', 'updated')->sole();

        expect($log->user_id)->toBe($this->user->id)
            ->and($log->tenant_id)->toBe($this->tenant->id)
            ->and($log->old_values)->toBe(['label' => 'Alt'])
            ->and($log->new_values)->toBe(['label' => 'Neu']);

        expect(AuditLog::where('auditable_id', $account->id)->pluck('event')->sort()->values()->all())
            ->toBe(['created', 'updated']);
    });
});

it('never logs timestamps or hidden attributes', function () {
    asTenant($this->tenant, function () {
        $account = BankAccount::factory()->create();
        $log = AuditLog::where('auditable_id', $account->id)->sole();

        expect($log->new_values)->not->toHaveKeys(['created_at', 'updated_at']);
    });
});

it('cannot be changed or deleted through the application', function () {
    asTenant($this->tenant, function () {
        $log = AuditLog::create(['event' => 'created', 'auditable_type' => 'x', 'auditable_id' => '1', 'tenant_id' => $this->tenant->id]);

        expect(fn () => $log->update(['event' => 'forged']))->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class);
    });
});

it('cannot be changed or deleted even with raw SQL', function () {
    asTenant($this->tenant, function () {
        AuditLog::create(['event' => 'created', 'auditable_type' => 'x', 'auditable_id' => '1', 'tenant_id' => $this->tenant->id]);

        expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->update(['event' => 'forged'])))
            ->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))
            ->toThrow(QueryException::class, 'append-only');
    });
});

it('keeps each dealer\'s change log private', function () {
    $other = makeTenant();
    asTenant($other, fn () => BankAccount::factory()->create());

    $visible = asTenant($this->tenant, fn () => AuditLog::count());
    $all = tenantContext()->bypass(fn () => AuditLog::count());

    expect($visible)->toBeLessThan($all);
    expect(asTenant($this->tenant, fn () => AuditLog::where('tenant_id', $other->id)->count()))->toBe(0);
});

it('records status changes in an append-only history', function () {
    asTenant($this->tenant, function () {
        $account = BankAccount::factory()->create();
        $entry = StatusHistory::record($account, 'draft', 'active', 'Test');

        expect($entry->tenant_id)->toBe($this->tenant->id)
            ->and($entry->user_id)->toBe($this->user->id);

        expect(fn () => DB::transaction(fn () => DB::table('status_history')->delete()))
            ->toThrow(QueryException::class, 'append-only');
    });
});
