<?php

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Tenancy\Database\RowLevelSecurity;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents with versions (nothing is ever overwritten), links to vehicle files and parties,
 * OCR text with full-text search, and the required-documents status per vehicle file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->jsonb('name');
            $table->string('folder_group', 40);
            $table->boolean('sensitive')->default(false);
            $table->unsignedSmallInteger('retention_years')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        RowLevelSecurity::enable('document_categories');

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('document_categories')->restrictOnDelete();
            $table->string('title');
            $table->date('document_on')->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('source', 20)->default('upload');
            $table->string('status', 30)->default('final');
            $table->uuid('current_version_id')->nullable();
            $table->uuid('possible_duplicate_of_id')->nullable();
            $table->string('legacy_ref', 60)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'legacy_ref']);
        });

        RowLevelSecurity::enable('documents');

        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('page_count')->nullable();
            $table->string('ocr_status', 20)->default('pending');
            $table->text('ocr_text')->nullable();
            $table->jsonb('data_snapshot')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->date('retain_until')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // Exact duplicate detection: the same file can exist only once per dealer.
            $table->unique(['tenant_id', 'sha256']);
            $table->unique(['document_id', 'version_no']);
        });

        // Full-text search over OCR text; "simple" so names, numbers and all four languages match.
        DB::statement("ALTER TABLE document_versions ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(ocr_text, ''))) STORED");
        DB::statement('CREATE INDEX document_versions_search ON document_versions USING gin (search_vector)');
        RowLevelSecurity::enable('document_versions');

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
            $table->foreign('possible_duplicate_of_id')->references('id')->on('documents')->nullOnDelete();
        });

        Schema::create('document_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 40);
            $table->uuid('linkable_id');
            $table->timestamps();

            $table->unique(['document_id', 'linkable_type', 'linkable_id']);
            $table->index(['tenant_id', 'linkable_type', 'linkable_id']);
        });

        RowLevelSecurity::enable('document_links');

        Schema::create('required_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('category_key', 40);
            $table->string('status', 20);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['stock_cycle_id', 'category_key']);
        });

        RowLevelSecurity::enable('required_documents');

        Schema::table('costs', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
        });

        $context = app(TenantContext::class);

        foreach ($context->bypass(fn () => Tenant::query()->get()) as $tenant) {
            $context->run($tenant, fn () => app(InstallDefaultDocumentCategories::class)());
        }
    }

    public function down(): void
    {
        Schema::table('costs', fn (Blueprint $table) => $table->dropForeign(['document_id']));
        Schema::dropIfExists('required_documents');
        Schema::dropIfExists('document_links');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
            $table->dropForeign(['possible_duplicate_of_id']);
        });
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
