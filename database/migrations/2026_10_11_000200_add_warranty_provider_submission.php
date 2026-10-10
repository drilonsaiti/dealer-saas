<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty providers (NSA, MultiPart …): how registrations and claims reach them. Without an
 * official API they go by e-mail (registration form / claim report as PDF, prepared as a draft
 * in the inbox and sent by a person). The e-mail is remembered on the warranty or claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_products', function (Blueprint $table) {
            $table->string('submission', 20)->default('manual'); // manual, email
            $table->string('provider_email', 200)->nullable();
        });

        Schema::table('warranties', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('submission_email_id')->nullable()->constrained('email_messages')->nullOnDelete();
        });

        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable();
            $table->foreignUuid('report_email_id')->nullable()->constrained('email_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', fn (Blueprint $table) => $table->dropConstrainedForeignId('report_email_id'));
        Schema::table('warranty_claims', fn (Blueprint $table) => $table->dropColumn('reported_at'));
        Schema::table('warranties', fn (Blueprint $table) => $table->dropConstrainedForeignId('submission_email_id'));
        Schema::table('warranties', fn (Blueprint $table) => $table->dropColumn('submitted_at'));
        Schema::table('warranty_products', fn (Blueprint $table) => $table->dropColumn(['submission', 'provider_email']));
    }
};
