<?php

use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The Phase 0 gate: data of one dealer is never visible to or writable by another,
 * enforced twice — by the application and by PostgreSQL Row-Level Security.
 */

beforeEach(function () {
    $this->a = makeTenant(['name' => 'Garage A']);
    $this->b = makeTenant(['name' => 'Garage B']);

    $this->accountA = asTenant($this->a, fn () => BankAccount::factory()->create(['label' => 'Konto A']));
    $this->accountB = asTenant($this->b, fn () => BankAccount::factory()->create(['label' => 'Konto B']));
});

it('connects as a database role that cannot bypass row-level security', function () {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

it('shows only the current dealer\'s rows through Eloquent', function () {
    asTenant($this->a, function () {
        expect(BankAccount::pluck('label')->all())->toBe(['Konto A']);
    });

    asTenant($this->b, function () {
        expect(BankAccount::pluck('label')->all())->toBe(['Konto B']);
    });
});

it('shows only the current dealer\'s rows even for raw SQL that forgets the filter', function () {
    asTenant($this->a, function () {
        expect(DB::table('bank_accounts')->count())->toBe(1)
            ->and(DB::table('bank_accounts')->where('tenant_id', $this->b->id)->count())->toBe(0);
    });
});

it('shows nothing when no dealer is selected', function () {
    expect(BankAccount::count())->toBe(0)
        ->and(DB::table('bank_accounts')->count())->toBe(0);
});

it('lets platform administration see all dealers only with an explicit bypass', function () {
    expect(tenantContext()->bypass(fn () => DB::table('bank_accounts')->count()))->toBe(2)
        ->and(DB::table('bank_accounts')->count())->toBe(0);
});

it('refuses to write a row for another dealer, even with raw SQL', function () {
    $insertForOtherDealer = fn () => asTenant($this->a, fn () => DB::transaction(fn () => DB::table('bank_accounts')->insert([
        'id' => (string) str()->uuid(),
        'tenant_id' => $this->b->id,
        'label' => 'Smuggled',
        'iban' => 'CH2106300505282032675',
        'currency' => 'CHF',
        'is_default' => false,
    ])));

    expect($insertForOtherDealer)->toThrow(QueryException::class, 'row-level security');
    expect(tenantContext()->bypass(fn () => DB::table('bank_accounts')->count()))->toBe(2);
});

it('cannot change or delete another dealer\'s rows', function () {
    asTenant($this->a, function () {
        expect(DB::table('bank_accounts')->where('id', $this->accountB->id)->update(['label' => 'Hacked']))->toBe(0)
            ->and(DB::table('bank_accounts')->where('id', $this->accountB->id)->delete())->toBe(0);
    });

    expect(tenantContext()->bypass(fn () => BankAccount::find($this->accountB->id)->label))->toBe('Konto B');
});

it('assigns new records to the current dealer automatically', function () {
    $account = asTenant($this->a, fn () => BankAccount::factory()->create([
        'label' => 'Neu',
        'iban' => 'CH0806300508115597491',
    ]));

    expect($account->tenant_id)->toBe($this->a->id);
});

it('reports the real database error when a write fails inside a dealer context', function () {
    // Same IBAN twice for one dealer violates the unique index; the context restore must not hide that.
    $duplicate = fn () => asTenant($this->a, fn () => DB::transaction(fn () => BankAccount::factory()->create()));

    expect($duplicate)->toThrow(QueryException::class, 'bank_accounts_tenant_id_iban_unique');
    expect(tenantContext()->hasTenant())->toBeFalse()
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as t")->t)->toBe('');
});

it('refuses to create tenant data without a dealer context', function () {
    BankAccount::factory()->create();
})->throws(MissingTenantContext::class);

it('restores the previous context after running as another dealer', function () {
    asTenant($this->a, function () {
        asTenant($this->b, fn () => expect(BankAccount::count())->toBe(1));

        expect(tenantContext()->id())->toBe($this->a->id)
            ->and(BankAccount::first()->label)->toBe('Konto A');
    });

    expect(tenantContext()->hasTenant())->toBeFalse();
});
