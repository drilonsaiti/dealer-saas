<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No foreign keys on purpose: audit rows must outlive the records they describe.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('event', 30);
            $table->string('auditable_type');
            $table->string('auditable_id', 64);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        RowLevelSecurity::enable('audit_logs');
        RowLevelSecurity::makeAppendOnly('audit_logs');

        Schema::create('status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('subject_type');
            $table->string('subject_id', 64);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->text('reason')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        RowLevelSecurity::enable('status_history');
        RowLevelSecurity::makeAppendOnly('status_history');
    }

    public function down(): void
    {
        Schema::dropIfExists('status_history');
        Schema::dropIfExists('audit_logs');
    }
};
