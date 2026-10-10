<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal calendar subscription (iCal) per user and dealer: a secret link, stored only as a
 * hash, that calendar apps poll. One active link per user and dealer; a new one replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_prefix', 12);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });

        // Looked up by hash before the dealer is known (bypass), then scoped by RLS.
        RowLevelSecurity::enable('calendar_feeds');
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
