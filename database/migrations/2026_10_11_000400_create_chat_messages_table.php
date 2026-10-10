<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Business messages (Phase 4): in and out, per customer phone number, matched to
 * contact and vehicle file like e-mails. The provider's message id makes webhooks idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_account_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in, out
            $table->string('external_id', 200)->nullable();
            $table->string('phone', 30);
            $table->string('contact_name', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20); // received, sent, delivered, read, failed
            $table->text('error')->nullable();
            $table->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stock_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['integration_account_id', 'external_id']);
            $table->index(['tenant_id', 'phone', 'created_at']);
        });

        RowLevelSecurity::enable('chat_messages');
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
