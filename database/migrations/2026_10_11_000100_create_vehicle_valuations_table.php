<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valuations from a licensed data provider (Auto-i-DAT), kept per vehicle file with the
 * mileage they were made for: a history for pricing, not overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_valuations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('external_id', 100)->nullable();
            $table->date('valued_on');
            $table->unsignedInteger('mileage');
            $table->bigInteger('retail_rp')->nullable();
            $table->bigInteger('trade_in_rp')->nullable();
            $table->string('reference', 100)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stock_cycle_id', 'valued_on']);
        });

        RowLevelSecurity::enable('vehicle_valuations');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_valuations');
    }
};
