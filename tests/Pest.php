<?php

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Purchasing\Actions\InstallDefaultCostCategories;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Models\StockCycle;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

function tenantContext(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * Create a tenant (dealer) the way the platform does: with bypass, outside any tenant.
 */
function makeTenant(array $attributes = []): Tenant
{
    return tenantContext()->bypass(fn () => Tenant::factory()->create($attributes));
}

/**
 * A dealer with the default number ranges and cost categories, as CreateTenant sets them up.
 */
function makeDealer(array $attributes = []): Tenant
{
    $tenant = makeTenant($attributes);

    asTenant($tenant, function () {
        foreach (NumberSequenceKey::cases() as $key) {
            NumberSequence::create([
                'key' => $key,
                'pattern' => $key->defaultPattern(),
                'reset_yearly' => $key->resetsYearlyByDefault(),
            ]);
        }

        app(InstallDefaultCostCategories::class)();
        app(InstallDefaultDocumentCategories::class)();
    });

    return $tenant;
}

function makeMember(Tenant $tenant, Role $role = Role::Sales, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    tenantContext()->run($tenant, fn () => $tenant->addMember($user, $role));

    return $user;
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return tenantContext()->run($tenant, $callback);
}

/**
 * Prepare Filament + tenant context for Livewire component tests in the dealer app panel.
 */
function useAppPanel(Tenant $tenant, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::bootCurrentPanel(); // registers the panel's tenancy scopes, as a real request does
    Filament::setTenant($tenant);
    tenantContext()->set($tenant);
}

/**
 * A small photo in the vehicle file (listing needs one). Uses a fresh image each call,
 * so the exact-duplicate check never refuses it.
 */
function attachPhoto(StockCycle $cycle): Document
{
    $path = tempnam(sys_get_temp_dir(), 'photo').'.png';
    $image = imagecreatetruecolor(4, 4);
    imagesetpixel($image, random_int(0, 3), random_int(0, 3), random_int(1, 0xFFFFFF));
    imagepng($image, $path);

    $category = DocumentCategory::query()->where('key', 'photo')->firstOrFail();

    return app(StoreDocument::class)($path, $category, ['original_name' => 'front.png'], [$cycle]);
}

function fakeFile(string $content, string $name = 'scan.pdf'): string
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $content);

    return $path.'|'.$name;
}

function storeDoc(string $categoryKey, string $content, array $attributes = [], array $links = []): Document
{
    [$path, $name] = explode('|', fakeFile($content, $attributes['original_name'] ?? 'scan.pdf'));
    $category = DocumentCategory::where('key', $categoryKey)->firstOrFail();

    return app(StoreDocument::class)($path, $category, ['original_name' => $name, ...$attributes], $links);
}
