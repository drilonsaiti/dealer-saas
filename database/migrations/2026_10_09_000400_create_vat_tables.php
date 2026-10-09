<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MWST: dated VAT profiles (method, basis, period, approved net tax rates), tax events created
 * by versioned rules from issued invoices and payments, and VAT periods that are previewed,
 * closed (figures frozen), exported, submitted and paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('liable')->default(true);
            $table->string('vat_number', 30)->nullable();
            $table->string('method', 20);
            $table->string('basis', 20)->default('agreed');
            $table->string('period', 20)->default('half_year');
            $table->date('approved_on')->nullable();
            $table->string('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'valid_from']);
        });

        RowLevelSecurity::enable('vat_profiles');

        Schema::create('vat_net_tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vat_profile_id')->constrained()->cascadeOnDelete();
            $table->string('activity_code', 10)->nullable();
            $table->jsonb('activity');
            $table->decimal('rate', 6, 4);
            $table->unsignedSmallInteger('sort')->default(1);
            $table->timestamps();
        });

        RowLevelSecurity::enable('vat_net_tax_rates');

        Schema::create('vat_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vat_profile_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->jsonb('figures')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->date('submitted_on')->nullable();
            $table->string('submission_reference', 100)->nullable();
            $table->date('paid_on')->nullable();
            $table->uuid('corrects_period_id')->nullable();
            $table->foreignUuid('report_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('detail_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('xml_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('submission_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX vat_periods_one_per_start ON vat_periods (tenant_id, starts_on) WHERE corrects_period_id IS NULL');

        Schema::table('vat_periods', function (Blueprint $table) {
            $table->foreign('corrects_period_id')->references('id')->on('vat_periods')->restrictOnDelete();
        });

        RowLevelSecurity::enable('vat_periods');

        Schema::create('tax_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->foreignUuid('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('event_on');
            $table->foreignUuid('vat_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('field', 10);
            $table->bigInteger('base_rp');
            $table->decimal('legal_rate', 6, 4)->default(0);
            $table->bigInteger('tax_rp')->default(0);
            $table->foreignUuid('net_tax_rate_id')->nullable()->constrained('vat_net_tax_rates')->restrictOnDelete();
            $table->decimal('net_rate', 6, 4)->nullable();
            $table->foreignUuid('period_id')->nullable()->constrained('vat_periods')->restrictOnDelete();
            $table->boolean('late')->default(false);
            $table->string('state', 20);
            $table->string('rule_key', 40);
            $table->string('rule_version', 20);
            $table->jsonb('explanation');
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period_id']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'state']);
        });

        // Closed figures are never recalculated: events of a closed period cannot change.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tax_events_protect_closed() RETURNS trigger AS $$
            DECLARE closed boolean;
            BEGIN
                SELECT status <> 'open' INTO closed FROM vat_periods WHERE id = OLD.period_id;
                IF closed THEN
                    RAISE EXCEPTION 'tax events of a closed VAT period cannot be changed';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER tax_events_closed_guard BEFORE UPDATE OR DELETE ON tax_events FOR EACH ROW WHEN (OLD.period_id IS NOT NULL) EXECUTE FUNCTION tax_events_protect_closed()');

        RowLevelSecurity::enable('tax_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_events');
        DB::statement('DROP FUNCTION IF EXISTS tax_events_protect_closed()');
        Schema::table('vat_periods', fn (Blueprint $table) => $table->dropForeign(['corrects_period_id']));
        Schema::dropIfExists('vat_periods');
        Schema::dropIfExists('vat_net_tax_rates');
        Schema::dropIfExists('vat_profiles');
    }
};
