<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own simple electronic signature (SES): one request per finalised document version, signers
 * in a set order (customer, then dealer), with the evidence of every signature. The signed
 * version is a new, sealed and locked document version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->string('provider', 20)->default('own');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('signed_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->jsonb('seal')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id']);
        });

        RowLevelSecurity::enable('signature_requests');

        Schema::create('signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('signature_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('role', 20);
            $table->string('method', 20);
            $table->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('locale', 5);
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('code_hash')->nullable();
            $table->string('code_channel', 10)->nullable();
            $table->timestamp('code_sent_at')->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->timestamp('code_verified_at')->nullable();
            $table->text('identification')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('signed_at')->nullable();
            $table->string('place')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('signature_path')->nullable();
            $table->char('signature_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['signature_request_id', 'position']);
        });

        RowLevelSecurity::enable('signers');
    }

    public function down(): void
    {
        Schema::dropIfExists('signers');
        Schema::dropIfExists('signature_requests');
    }
};
