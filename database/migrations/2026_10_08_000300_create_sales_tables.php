<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales, their extra items, and trade-ins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->foreignUuid('buyer_party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('invoice_recipient_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('holder_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->date('sale_on')->nullable();
            $table->date('planned_handover_on')->nullable();
            $table->date('reserved_until')->nullable();
            $table->bigInteger('price_rp');
            $table->bigInteger('discount_rp')->default(0);
            $table->bigInteger('deposit_rp')->default(0);
            $table->string('payment_type', 20)->default('bank');
            $table->unsignedInteger('mileage_at_handover')->nullable();
            $table->date('delivered_on')->nullable();
            $table->string('locale', 5)->default('de');
            $table->text('remarks')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('legacy_ref', 60)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sale_on']);
            $table->index(['tenant_id', 'buyer_party_id']);
        });

        // No double sale or double reservation of the same car, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX sales_one_active_per_cycle ON sales (stock_cycle_id) WHERE status <> 'cancelled'");
        RowLevelSecurity::enable('sales');

        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description');
            $table->decimal('qty', 8, 2)->default(1);
            $table->bigInteger('unit_price_rp');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
        });

        RowLevelSecurity::enable('sale_items');

        Schema::create('trade_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('vehicle_data');
            $table->unsignedInteger('mileage')->nullable();
            $table->foreignUuid('purchase_cycle_id')->nullable()->constrained('stock_cycles')->nullOnDelete();
            $table->bigInteger('value_rp');
            $table->bigInteger('payoff_rp')->default(0);
            $table->foreignUuid('payoff_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->bigInteger('customer_payout_rp')->default(0);
            $table->bigInteger('customer_topup_rp')->default(0);
            // Credited amount = value − payoff − payout to customer + customer top-up (spec rule 5).
            $table->bigInteger('credited_rp')->storedAs('value_rp - payoff_rp - customer_payout_rp + customer_topup_rp');
            $table->string('settlement_basis')->nullable();
            $table->text('condition_notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('trade_ins');

        Schema::table('commitments', function (Blueprint $table) {
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commitments', fn (Blueprint $table) => $table->dropForeign(['sale_id']));
        Schema::dropIfExists('trade_ins');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
