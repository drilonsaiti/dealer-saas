<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting export (Phase 3): the dealer's account numbers per booking type, and the exports
 * with the records they contained. A record (invoice, payment, purchase, cost) can be in only
 * one export (unique), so nothing is ever booked twice by the accountant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('account', 20);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        RowLevelSecurity::enable('account_mappings');

        Schema::create('accounting_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->date('until_on');
            $table->unsignedInteger('records_count')->default(0);
            $table->unsignedInteger('entries_count')->default(0);
            $table->bigInteger('total_rp')->default(0);
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        RowLevelSecurity::enable('accounting_exports');

        Schema::create('accounting_export_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('accounting_export_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'source_type', 'source_id']);
        });

        RowLevelSecurity::enable('accounting_export_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_items');
        Schema::dropIfExists('accounting_exports');
        Schema::dropIfExists('account_mappings');
    }
};
