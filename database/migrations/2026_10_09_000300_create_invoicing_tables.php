<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices with Swiss QR bill, credit notes, payments with allocations, and bank statement
 * lines (camt.053/054) matched to invoices. Plus the VAT basics the invoice lines need:
 * legal rates (dated, for everyone) and the dealer's VAT codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Legal Swiss VAT rates, the same for every dealer and never changed in place.
        Schema::create('vat_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->decimal('rate', 6, 4);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->unique(['code', 'valid_from']);
        });

        DB::table('vat_rates')->insert([
            ['code' => 'normal', 'rate' => 7.7, 'valid_from' => '2018-01-01', 'valid_to' => '2023-12-31'],
            ['code' => 'reduced', 'rate' => 2.5, 'valid_from' => '2018-01-01', 'valid_to' => '2023-12-31'],
            ['code' => 'accommodation', 'rate' => 3.7, 'valid_from' => '2018-01-01', 'valid_to' => '2023-12-31'],
            ['code' => 'normal', 'rate' => 8.1, 'valid_from' => '2024-01-01', 'valid_to' => null],
            ['code' => 'reduced', 'rate' => 2.6, 'valid_from' => '2024-01-01', 'valid_to' => null],
            ['code' => 'accommodation', 'rate' => 3.8, 'valid_from' => '2024-01-01', 'valid_to' => null],
        ]);

        Schema::create('vat_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->jsonb('label');
            $table->string('kind', 20);
            $table->string('vat_rate_code', 20)->nullable();
            $table->string('estv_field', 10)->nullable();
            $table->string('account', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        RowLevelSecurity::enable('vat_codes');

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->nullable();
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stock_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recipient_party_id')->constrained('parties')->restrictOnDelete();
            $table->jsonb('recipient_snapshot')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->date('service_on')->nullable();
            $table->string('locale', 5);
            $table->bigInteger('net_rp')->default(0);
            $table->bigInteger('vat_rp')->default(0);
            $table->bigInteger('total_rp')->default(0);
            $table->bigInteger('paid_rp')->default(0);
            $table->bigInteger('credited_rp')->default(0);
            $table->foreignUuid('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('qr_iban', 34)->nullable();
            $table->string('reference_type', 4)->nullable();
            $table->string('qr_reference', 27)->nullable();
            $table->uuid('credits_invoice_id')->nullable();
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('rules_version', 20)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sale_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('credits_invoice_id')->references('id')->on('invoices')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX invoices_number_unique ON invoices (tenant_id, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX invoices_reference_unique ON invoices (tenant_id, qr_reference) WHERE qr_reference IS NOT NULL');
        RowLevelSecurity::enable('invoices');

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 30);
            $table->string('description', 500);
            $table->decimal('qty', 10, 2)->default(1);
            $table->bigInteger('unit_price_rp');
            $table->foreignUuid('vat_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('vat_rate', 6, 4)->default(0);
            $table->bigInteger('net_rp');
            $table->bigInteger('vat_rp');
            $table->bigInteger('total_rp');
            $table->uuid('source_invoice_id')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('invoice_lines');

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->date('paid_on');
            $table->bigInteger('amount_rp');
            $table->string('method', 30);
            $table->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 60)->nullable();
            $table->uuid('bank_transaction_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('legacy_ref', 60)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'paid_on']);
        });

        RowLevelSecurity::enable('payments');

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('allocatable_type', 40);
            $table->uuid('allocatable_id');
            $table->bigInteger('amount_rp');
            $table->timestamps();

            $table->index(['tenant_id', 'allocatable_type', 'allocatable_id']);
        });

        RowLevelSecurity::enable('payment_allocations');

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained()->restrictOnDelete();
            $table->char('entry_key', 64);
            $table->date('booked_on');
            $table->date('value_on')->nullable();
            $table->bigInteger('amount_rp');
            $table->char('currency', 3)->default('CHF');
            $table->string('reference', 40)->nullable();
            $table->string('counterparty')->nullable();
            $table->string('remittance', 500)->nullable();
            $table->jsonb('raw')->nullable();
            $table->string('match_status', 20)->default('unmatched');
            $table->foreignUuid('proposed_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_file')->nullable();
            $table->timestamps();

            // Importing the same statement twice changes nothing.
            $table->unique(['tenant_id', 'entry_key']);
            $table->index(['tenant_id', 'match_status']);
        });

        RowLevelSecurity::enable('bank_transactions');

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('bank_transaction_id')->references('id')->on('bank_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropForeign(['bank_transaction_id']));
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('vat_codes');
        Schema::dropIfExists('vat_rates');
    }
};
