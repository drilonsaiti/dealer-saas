<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generated documents (contracts first): versioned templates per dealer with the clauses in
 * all four languages, and on every generated version the template version and data it was
 * made from, so any PDF can be reproduced and explained later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type_key', 40);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('valid_from')->nullable();
            $table->jsonb('clauses');
            $table->jsonb('footer')->nullable();
            $table->string('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'type_key', 'version']);
        });

        // One active version per document type and dealer.
        DB::statement("CREATE UNIQUE INDEX document_templates_one_active ON document_templates (tenant_id, type_key) WHERE status = 'active'");
        RowLevelSecurity::enable('document_templates');

        Schema::table('documents', function (Blueprint $table) {
            $table->string('type_key', 40)->nullable()->after('category_id');
            $table->string('number', 40)->nullable()->after('title');
            $table->index(['tenant_id', 'type_key']);
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->foreignUuid('template_version_id')->nullable()->after('data_snapshot')->constrained('document_templates')->restrictOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('logo_path'));
        Schema::table('document_versions', fn (Blueprint $table) => $table->dropConstrainedForeignId('template_version_id'));
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type_key']);
            $table->dropColumn(['type_key', 'number']);
        });
        Schema::dropIfExists('document_templates');
    }
};
