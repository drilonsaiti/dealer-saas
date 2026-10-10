<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: own listings (shown on the dealer's website through the public API), API tokens,
 * website enquiries, outgoing webhooks with a delivery log, and per-channel publications
 * (website now, AutoScout24 in the connector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft'); // draft, published, withdrawn
            $table->jsonb('title');
            $table->jsonb('description')->nullable();
            $table->jsonb('highlights')->nullable(); // list of short features
            $table->bigInteger('price_rp');
            $table->boolean('show_price')->default(true);
            $table->jsonb('photo_document_ids')->nullable(); // ordered, first = cover
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique('stock_cycle_id');
            $table->index(['tenant_id', 'status']);
        });

        RowLevelSecurity::enable('listings');

        Schema::create('listing_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('status', 20)->default('pending'); // pending, published, failed, removed
            $table->string('external_id', 100)->nullable();
            $table->string('external_url')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'channel']);
        });

        RowLevelSecurity::enable('listing_publications');

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_prefix', 12);
            $table->string('token_hash', 64)->unique();
            $table->jsonb('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        // Looked up by hash before the tenant is known (bypass), then scoped by RLS.
        RowLevelSecurity::enable('api_tokens');

        Schema::create('enquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stock_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('website');
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('message');
            $table->string('locale', 5)->nullable();
            $table->string('status', 20)->default('new'); // new, in_progress, closed
            $table->uuid('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        RowLevelSecurity::enable('enquiries');

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secret'); // encrypted
            $table->jsonb('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('webhook_endpoints');

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 60);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending'); // pending, delivered, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'endpoint_id', 'created_at']);
        });

        RowLevelSecurity::enable('webhook_deliveries');
    }

    public function down(): void
    {
        foreach (['webhook_deliveries', 'webhook_endpoints', 'enquiries', 'api_tokens', 'listing_publications', 'listings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
