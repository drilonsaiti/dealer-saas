<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle ≠ transaction: a vehicle is the physical car (unique per dealer by Stammnummer),
 * a stock cycle is one purchase-to-sale pass of that car through the dealership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('stammnummer', 9)->nullable();
            $table->string('vin', 17)->nullable();
            $table->string('make', 60)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('variant', 120)->nullable();
            $table->string('internal_label')->nullable();
            $table->string('type_approval', 20)->nullable();
            $table->string('body_type', 30)->nullable();
            $table->string('vehicle_type', 30)->default('passenger_car');
            $table->string('fuel', 30)->nullable();
            $table->string('transmission', 20)->nullable();
            $table->string('drive', 20)->nullable();
            $table->unsignedSmallInteger('power_kw')->nullable();
            $table->unsignedInteger('displacement_cc')->nullable();
            $table->unsignedTinyInteger('doors')->nullable();
            $table->unsignedTinyInteger('seats')->nullable();
            $table->string('color_exterior', 60)->nullable();
            $table->string('color_interior', 60)->nullable();
            $table->date('first_registration_on')->nullable();
            $table->date('last_registration_on')->nullable();
            $table->string('plate', 20)->nullable();
            $table->unsignedInteger('curb_weight_kg')->nullable();
            $table->unsignedInteger('total_weight_kg')->nullable();
            $table->unsignedTinyInteger('keys_count')->nullable();
            $table->date('mfk_last_on')->nullable();
            $table->date('mfk_due_on')->nullable();
            $table->date('service_last_on')->nullable();
            $table->unsignedInteger('service_last_km')->nullable();
            $table->date('service_next_on')->nullable();
            $table->jsonb('equipment')->nullable();
            $table->jsonb('description')->nullable();
            $table->text('internal_notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vin']);
            $table->index(['tenant_id', 'plate']);
        });

        // The Stammnummer identifies a car in Switzerland; a vehicle may exist without it and get it later.
        DB::statement('CREATE UNIQUE INDEX vehicles_tenant_stammnummer_unique ON vehicles (tenant_id, stammnummer) WHERE stammnummer IS NOT NULL');
        RowLevelSecurity::enable('vehicles');

        Schema::create('tyre_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('season', 20);
            $table->string('dimension', 40)->nullable();
            $table->decimal('tread_mm', 3, 1)->nullable();
            $table->boolean('on_rims')->default(false);
            $table->string('rim_type', 40)->nullable();
            $table->string('location', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vehicle_id']);
        });

        RowLevelSecurity::enable('tyre_sets');

        Schema::create('stock_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->nullable();
            $table->string('status', 30);
            $table->unsignedSmallInteger('file_year')->nullable();
            $table->date('purchased_on')->nullable();
            $table->date('ready_on')->nullable();
            $table->date('listed_on')->nullable();
            $table->date('sold_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->date('archived_on')->nullable();
            $table->bigInteger('planned_price_rp')->nullable();
            $table->bigInteger('list_price_rp')->nullable();
            $table->unsignedInteger('mileage_in')->nullable();
            $table->unsignedInteger('mileage_out')->nullable();
            $table->text('notes')->nullable();
            $table->string('legacy_ref', 60)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'file_year']);
            $table->index(['tenant_id', 'legacy_ref']);
        });

        DB::statement('CREATE UNIQUE INDEX stock_cycles_tenant_number_unique ON stock_cycles (tenant_id, number) WHERE number IS NOT NULL');
        // A car can only be in stock once at a time; finished and cancelled cycles don't count.
        DB::statement("CREATE UNIQUE INDEX stock_cycles_one_open_per_vehicle ON stock_cycles (vehicle_id) WHERE status NOT IN ('delivered', 'archived', 'cancelled')");
        RowLevelSecurity::enable('stock_cycles');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cycles');
        Schema::dropIfExists('tyre_sets');
        Schema::dropIfExists('vehicles');
    }
};
