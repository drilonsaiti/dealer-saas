<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leasing / credit financings with payout and buy-back obligations, warranty products,
 * policies and claims, and checklists (handover, financing partner) with automatic rules.
 * Code 178 (no change of holder) is a status of the vehicle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('code178_status', 20)->default('none');
            $table->date('code178_changed_on')->nullable();
            $table->string('code178_note')->nullable();
        });

        Schema::create('financings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('partner_party_id')->constrained('parties')->restrictOnDelete();
            $table->string('kind', 20)->default('leasing');
            $table->string('contract_number', 60)->nullable();
            $table->string('status', 30)->default('applied');
            $table->date('applied_on');
            $table->bigInteger('cash_price_rp');
            $table->bigInteger('collection_rp')->default(0);
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedInteger('km_per_year')->nullable();
            $table->bigInteger('residual_rp')->nullable();
            $table->decimal('nominal_rate', 6, 3)->nullable();
            $table->bigInteger('monthly_rate_rp')->nullable();
            $table->boolean('has_buyback')->default(false);
            $table->date('contract_received_on')->nullable();
            $table->date('revocation_until')->nullable();
            $table->date('documents_sent_on')->nullable();
            $table->date('payout_due_on')->nullable();
            $table->bigInteger('payout_expected_rp')->default(0);
            $table->date('payout_received_on')->nullable();
            $table->string('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX financings_one_active_per_sale ON financings (sale_id) WHERE status NOT IN ('rejected', 'cancelled')");
        RowLevelSecurity::enable('financings');

        Schema::create('buyback_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('financing_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_rp');
            $table->date('due_on');
            $table->date('remind_on');
            $table->string('status', 20)->default('open');
            $table->foreignUuid('stock_cycle_id')->nullable()->constrained()->nullOnDelete(); // the buy-back file once exercised
            $table->date('settled_on')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique('financing_id');
        });

        RowLevelSecurity::enable('buyback_obligations');

        Schema::create('warranty_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('provider_party_id')->nullable()->constrained('parties')->restrictOnDelete(); // null = own warranty
            $table->jsonb('name');
            $table->unsignedSmallInteger('duration_months');
            $table->unsignedInteger('km_limit')->nullable();
            $table->bigInteger('coverage_limit_rp')->nullable();
            $table->jsonb('coverage')->nullable();
            $table->bigInteger('deductible_rp')->default(0);
            $table->bigInteger('cost_rp')->default(0);
            $table->bigInteger('price_rp')->default(0);
            $table->bigInteger('commission_rp')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        RowLevelSecurity::enable('warranty_products');

        Schema::create('warranties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('warranty_products')->restrictOnDelete();
            $table->foreignUuid('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->foreignUuid('cost_id')->nullable()->constrained('costs')->nullOnDelete();
            $table->string('policy_number', 60)->nullable();
            $table->unsignedSmallInteger('duration_months');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('km_at_start')->nullable();
            $table->unsignedInteger('km_limit')->nullable();
            $table->bigInteger('coverage_limit_rp')->nullable();
            $table->bigInteger('deductible_rp')->default(0);
            $table->bigInteger('cost_rp')->default(0);
            $table->bigInteger('price_rp')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('certificate_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'ends_on']);
        });

        RowLevelSecurity::enable('warranties');

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('warranty_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->unsignedInteger('mileage');
            $table->text('description');
            $table->text('diagnosis')->nullable();
            $table->foreignUuid('workshop_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->bigInteger('amount_rp')->default(0);
            $table->bigInteger('deductible_rp')->default(0);
            $table->bigInteger('provider_share_rp')->default(0);
            $table->bigInteger('dealer_share_rp')->default(0);
            $table->string('status', 20)->default('open');
            $table->foreignUuid('cost_id')->nullable()->constrained('costs')->nullOnDelete();
            $table->date('closed_on')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('warranty_claims');

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->foreignUuid('partner_party_id')->nullable()->constrained('parties')->nullOnDelete(); // financing partner template
            $table->jsonb('name');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        RowLevelSecurity::enable('checklist_templates');

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->string('key', 60);
            $table->jsonb('label');
            $table->boolean('required')->default(true);
            $table->string('auto_rule', 80)->nullable();
            $table->unsignedSmallInteger('sort')->default(1);
            $table->timestamps();
        });

        RowLevelSecurity::enable('checklist_template_items');

        Schema::create('checklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('template_id')->constrained('checklist_templates')->restrictOnDelete();
            $table->string('kind', 30);
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('financing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['sale_id', 'kind', 'financing_id']);
        });

        RowLevelSecurity::enable('checklists');

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('checklist_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->jsonb('label');
            $table->boolean('required')->default(true);
            $table->string('auto_rule', 80)->nullable();
            $table->unsignedSmallInteger('sort')->default(1);
            $table->boolean('applicable')->default(true);
            $table->timestamp('done_at')->nullable();
            $table->uuid('done_by')->nullable();
            $table->boolean('auto')->default(false);
            $table->foreignUuid('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
        });

        RowLevelSecurity::enable('checklist_items');
    }

    public function down(): void
    {
        foreach (['checklist_items', 'checklists', 'checklist_template_items', 'checklist_templates', 'warranty_claims', 'warranties', 'warranty_products', 'buyback_obligations', 'financings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['code178_status', 'code178_changed_on', 'code178_note']));
    }
};
