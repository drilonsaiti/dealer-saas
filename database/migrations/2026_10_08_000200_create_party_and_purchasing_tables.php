<?php

use App\Domain\Purchasing\Actions\InstallDefaultCostCategories;
use App\Domain\Tenancy\Database\RowLevelSecurity;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parties (anyone the dealer deals with), purchases, costs and commitments (Zusagen).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Name similarity for duplicate warnings. pg_trgm is a trusted extension: the database owner may create it.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->jsonb('roles');
            $table->string('salutation', 20)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name')->nullable();
            $table->string('uid', 20)->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->boolean('vat_registered')->nullable();
            $table->string('street')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->default('CH');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('mobile_normalized', 20)->nullable();
            $table->text('birth_date')->nullable();
            $table->string('id_doc_type', 30)->nullable();
            $table->text('id_doc_number')->nullable();
            $table->string('locale', 5)->default('de');
            $table->timestamp('consent_marketing_at')->nullable();
            $table->timestamp('privacy_ack_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('legacy_ref', 60)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone_normalized']);
            $table->index(['tenant_id', 'mobile_normalized']);
            $table->index(['tenant_id', 'legacy_ref']);
        });

        DB::statement("CREATE INDEX parties_name_trgm ON parties USING gin ((lower(coalesce(company_name, '') || ' ' || coalesce(first_name, '') || ' ' || coalesce(last_name, ''))) gin_trgm_ops)");
        RowLevelSecurity::enable('parties');

        Schema::create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('seller_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('seller_kind', 20);
            $table->string('purchase_type', 20)->default('direct');
            $table->date('contract_on');
            $table->date('delivered_on')->nullable();
            $table->bigInteger('price_rp');
            $table->string('vat_situation', 30)->default('unknown');
            $table->bigInteger('vat_shown_rp')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->bigInteger('payoff_rp')->nullable();
            $table->foreignUuid('payoff_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->text('known_defects')->nullable();
            $table->text('agreed_deliverables')->nullable();
            $table->string('payment_status', 20)->default('open');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'seller_party_id']);
        });

        RowLevelSecurity::enable('purchases');

        Schema::create('cost_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->jsonb('name');
            $table->boolean('counts_toward_margin')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        RowLevelSecurity::enable('cost_categories');

        Schema::create('commitments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->uuid('sale_id')->nullable();
            $table->string('description');
            $table->bigInteger('estimated_cost_rp')->default(0);
            $table->uuid('cost_id')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->uuid('done_by')->nullable();
            $table->boolean('blocks_handover')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stock_cycle_id']);
        });

        RowLevelSecurity::enable('commitments');

        Schema::create('costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->constrained('cost_categories')->restrictOnDelete();
            $table->date('incurred_on');
            $table->string('description')->nullable();
            $table->foreignUuid('supplier_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->uuid('document_id')->nullable();
            $table->bigInteger('gross_rp');
            $table->bigInteger('vat_rp')->nullable();
            $table->boolean('is_estimate')->default(false);
            $table->string('status', 20)->default('draft');
            $table->uuid('split_group_id')->nullable();
            $table->foreignUuid('commitment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('legacy_ref', 60)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stock_cycle_id']);
            $table->index(['tenant_id', 'incurred_on']);
            $table->index(['tenant_id', 'split_group_id']);
        });

        RowLevelSecurity::enable('costs');

        Schema::table('commitments', function (Blueprint $table) {
            $table->foreign('cost_id')->references('id')->on('costs')->nullOnDelete();
        });

        // Existing dealers get the default cost categories too (new ones get them from CreateTenant).
        $context = app(TenantContext::class);
        $tenants = $context->bypass(fn () => Tenant::query()->get());

        foreach ($tenants as $tenant) {
            $context->run($tenant, fn () => app(InstallDefaultCostCategories::class)());
        }
    }

    public function down(): void
    {
        Schema::table('commitments', fn (Blueprint $table) => $table->dropForeign(['cost_id']));
        Schema::dropIfExists('costs');
        Schema::dropIfExists('commitments');
        Schema::dropIfExists('cost_categories');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('parties');
    }
};
