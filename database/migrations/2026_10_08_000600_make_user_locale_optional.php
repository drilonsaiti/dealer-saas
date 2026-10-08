<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's own language is optional: empty means "follow my dealer's language" (platform
 * administrators: the browser's). Until now every user got "de" at creation, which beat the
 * dealer's setting, so those values were defaults, not choices, and are cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            $table->string('locale', 5)->nullable()->default(null)->change();
        });

        DB::table('users')->update(['locale' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('locale')->update(['locale' => 'de']);

        Schema::table('users', function ($table) {
            $table->string('locale', 5)->nullable(false)->default('de')->change();
        });
    }
};
