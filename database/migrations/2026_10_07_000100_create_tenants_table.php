<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        RowLevelSecurity::installFunctions();

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('uid', 20)->nullable()->comment('Swiss company UID, e.g. CHE-404.944.758');
            $table->string('vat_number', 30)->nullable();
            $table->string('street')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('CH');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('default_locale', 5)->default('de');
            $table->string('brand_color', 7)->nullable();
            $table->jsonb('settings')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        // Memberships: who works for which dealer, with which role.
        // Not protected by RLS: login and the tenant switcher read the user's memberships across tenants.
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
        RowLevelSecurity::dropFunctions();
    }
};
