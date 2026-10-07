<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('bank_name')->nullable();
            $table->string('account_holder')->nullable();
            $table->string('iban', 34);
            $table->string('qr_iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->char('currency', 3)->default('CHF');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'iban']);
        });

        RowLevelSecurity::enable('bank_accounts');

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('pattern', 60);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->boolean('reset_yearly')->default(false);
            $table->unsignedSmallInteger('current_year')->nullable();
            $table->unsignedBigInteger('issued_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        RowLevelSecurity::enable('number_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('bank_accounts');
    }
};
