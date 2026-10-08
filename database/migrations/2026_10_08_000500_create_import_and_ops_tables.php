<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import framework (runs, rows, mapping presets) and the platform's restore-drill log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('importer', 30);
            $table->string('name');
            $table->jsonb('mapping');
            $table->jsonb('options')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'importer', 'name']);
        });

        RowLevelSecurity::enable('import_presets');

        Schema::create('import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('importer', 30);
            $table->foreignUuid('preset_id')->nullable()->constrained('import_presets')->nullOnDelete();
            $table->string('file_name');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('status', 20);
            $table->jsonb('mapping')->nullable();
            $table->jsonb('options')->nullable();
            $table->jsonb('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        RowLevelSecurity::enable('import_runs');

        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('import_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('row_ref');
            $table->jsonb('payload')->nullable();
            $table->string('action', 20);
            $table->jsonb('messages')->nullable();
            $table->string('record_type', 40)->nullable();
            $table->uuid('record_id')->nullable();
            $table->jsonb('created_records')->nullable();
            $table->timestamps();

            $table->index(['import_run_id', 'position']);
            $table->index(['import_run_id', 'action']);
        });

        RowLevelSecurity::enable('import_rows');

        // Platform operations, not dealer data: no tenant_id, only visible in the platform panel.
        Schema::create('restore_drills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 10);
            $table->string('backup_file')->nullable();
            $table->unsignedInteger('tenants')->nullable();
            $table->unsignedBigInteger('audit_rows')->nullable();
            $table->unsignedInteger('rls_tables')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_drills');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('import_presets');
    }
};
