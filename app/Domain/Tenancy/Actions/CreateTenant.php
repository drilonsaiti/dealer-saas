<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Purchasing\Actions\InstallDefaultCostCategories;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sets up a new dealer: the tenant, its default number sequences and cost categories, and its first administrator.
 * Used by the platform panel and by seeders.
 */
class CreateTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InviteMember $inviteMember,
        private readonly InstallDefaultCostCategories $installCostCategories,
        private readonly InstallDefaultDocumentCategories $installDocumentCategories,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  tenant attributes (name required)
     */
    public function __invoke(array $attributes, string $adminEmail, ?string $adminName = null, bool $sendInvitation = true): Tenant
    {
        return $this->context->bypass(fn (): Tenant => DB::transaction(function () use ($attributes, $adminEmail, $adminName, $sendInvitation): Tenant {
            $attributes['slug'] ??= $this->uniqueSlug((string) $attributes['name']);

            $tenant = Tenant::create($attributes);

            $this->context->run($tenant, function () use ($tenant, $adminEmail, $adminName, $sendInvitation): void {
                foreach (NumberSequenceKey::cases() as $key) {
                    NumberSequence::create([
                        'key' => $key,
                        'pattern' => $key->defaultPattern(),
                        'reset_yearly' => $key->resetsYearlyByDefault(),
                    ]);
                }

                ($this->installCostCategories)();
                ($this->installDocumentCategories)();

                ($this->inviteMember)($tenant, $adminEmail, $adminName, Role::Administrator, $sendInvitation);
            });

            return $tenant;
        }));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'dealer';
        $slug = $base;
        $i = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
