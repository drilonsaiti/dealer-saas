<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every AI request of a dealer: purpose, model, tokens, result status. For cost control and
 * transparency (what was sent where).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->string('purpose', 30);
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('model', 60);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('status', 10); // ok, error
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });

        RowLevelSecurity::enable('ai_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
