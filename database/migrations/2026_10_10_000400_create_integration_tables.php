<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrations (concept 11): one account per dealer and provider (AutoScout24 first) with its
 * own credentials, and a visible sync log of every call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->jsonb('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('status', 20)->default('unchecked'); // unchecked, ok, error
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        RowLevelSecurity::enable('integration_accounts');

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('status', 20); // ok, error, skipped
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'integration_account_id', 'created_at']);
        });

        RowLevelSecurity::enable('integration_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('integration_accounts');
    }
};
