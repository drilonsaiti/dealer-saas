<?php

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail inbox (Phase 3): the dealer's own mailboxes (IMAP in, SMTP out, passwords encrypted)
 * and the messages, matched to contacts and vehicle files. Fetching is idempotent per mailbox
 * by Message-ID and by IMAP UID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('email', 200);
            $table->string('imap_host', 200);
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl'); // ssl, tls (STARTTLS), none
            $table->string('imap_username', 200);
            $table->string('imap_folder', 200)->default('INBOX');
            $table->string('smtp_host', 200)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();
            $table->string('smtp_username', 200)->nullable();
            $table->text('secrets')->nullable(); // encrypted JSON: imap_password, smtp_password
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamp('last_fetched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        RowLevelSecurity::enable('mailboxes');

        Schema::create('email_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in, out
            $table->string('status', 20); // received, draft, sent, failed
            $table->string('message_id', 300)->nullable();
            $table->string('in_reply_to', 300)->nullable();
            $table->unsignedBigInteger('uid')->nullable();
            $table->string('from_address', 200)->nullable();
            $table->string('from_name', 200)->nullable();
            $table->jsonb('to')->nullable();
            $table->jsonb('cc')->nullable();
            $table->string('subject', 500)->nullable();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->timestamp('sent_at')->nullable(); // Date header (in) or sending time (out)
            $table->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stock_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('matched_by', 30)->nullable();
            $table->uuid('reply_to_message_id')->nullable();
            $table->jsonb('quarantined')->nullable(); // attachments not stored: [{name, reason}]
            $table->timestamp('read_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->uuid('handled_by')->nullable();
            $table->text('error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'direction', 'status', 'sent_at']);
            $table->index(['tenant_id', 'stock_cycle_id']);
            $table->index(['tenant_id', 'party_id']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreign('reply_to_message_id')->references('id')->on('email_messages')->nullOnDelete();
        });

        // Same message fetched twice (two runs, or after UIDVALIDITY changed) is stored once.
        DB::statement('CREATE UNIQUE INDEX email_messages_mailbox_message_id_unique ON email_messages (mailbox_id, message_id) WHERE direction = \'in\' AND message_id IS NOT NULL');

        RowLevelSecurity::enable('email_messages');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('mailboxes');
    }
};
