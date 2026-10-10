<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer portal (Phase 4): a personal secret link per sale for the buyer (status, documents,
 * invoices, warranty, uploads). Only the hash is stored; links expire and can be revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->string('token_prefix', 12);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        // Looked up by hash before the dealer is known (bypass), then scoped by RLS.
        RowLevelSecurity::enable('portal_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_links');
    }
};
