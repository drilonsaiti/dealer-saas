<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preparation (concept 10.5): condition reports with damages (photos as documents), repair
 * orders to workshops (estimate → approved → done with the actual cost), a target date and
 * "release for sale".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_cycles', function (Blueprint $table) {
            $table->date('prep_target_on')->nullable();
            $table->timestamp('released_for_sale_at')->nullable();
            $table->uuid('released_for_sale_by')->nullable();
        });

        Schema::create('condition_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->date('reported_on');
            $table->uuid('user_id')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('items')->nullable(); // area => [rating, note]
            $table->timestamps();
        });

        RowLevelSecurity::enable('condition_reports');

        Schema::create('repair_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workshop_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->text('description');
            $table->string('status', 20)->default('estimate');
            $table->bigInteger('estimate_rp')->nullable();
            $table->bigInteger('approved_rp')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->date('target_on')->nullable();
            $table->date('done_on')->nullable();
            $table->foreignUuid('cost_id')->nullable()->constrained('costs')->nullOnDelete();
            $table->boolean('blocks_release')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stock_cycle_id', 'status']);
        });

        RowLevelSecurity::enable('repair_orders');

        Schema::create('damages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('condition_report_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('area', 30);
            $table->string('kind', 30);
            $table->string('severity', 20)->default('minor');
            $table->string('notes')->nullable();
            $table->foreignUuid('repair_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        RowLevelSecurity::enable('damages');
    }

    public function down(): void
    {
        Schema::dropIfExists('damages');
        Schema::dropIfExists('repair_orders');
        Schema::dropIfExists('condition_reports');
        Schema::table('stock_cycles', fn (Blueprint $table) => $table->dropColumn(['prep_target_on', 'released_for_sale_at', 'released_for_sale_by']));
    }
};
