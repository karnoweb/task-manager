<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tm_comments', function (Blueprint $t) {
            $t->timestamp('created_at', 6)->nullable(); $t->timestamp('edited_at', 6)->nullable(); $t->foreignId('parent_comment_id')->nullable()->constrained('tm_comments')->restrictOnDelete();
        });
        Schema::table('tm_attachments', function (Blueprint $t) { $t->timestamp('created_at', 6)->nullable(); $t->boolean('storage_verified')->default(false); });
        Schema::create('tm_comment_revisions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('comment_id')->constrained('tm_comments')->restrictOnDelete(); $t->unsignedInteger('version');
            $t->text('body'); $t->string('editor_ref', 120); $t->timestamp('occurred_at', 6)->nullable(); $t->boolean('deleted')->default(false); $t->unique(['comment_id', 'version']);
        });
        DB::table('tm_comments')->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) { DB::table('tm_comment_revisions')->insert(['comment_id' => $row->id, 'version' => $row->version, 'body' => $row->body, 'editor_ref' => $row->author_ref, 'occurred_at' => null, 'deleted' => $row->deleted_at !== null]); }
        });
        Schema::create('tm_comment_mentions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('comment_id')->constrained('tm_comments')->restrictOnDelete(); $t->string('actor_ref', 120); $t->primary(['comment_id', 'actor_ref']);
        });
        Schema::create('tm_task_watchers', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete(); $t->string('actor_ref', 120); $t->timestamp('created_at', 6); $t->primary(['task_id', 'actor_ref']);
        });
        Schema::create('tm_checklists', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('public_key')->unique(); $t->string('scope_key', 120); $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('title'); $t->unsignedInteger('version')->default(1); $t->timestamp('created_at', 6); $t->timestamp('deleted_at', 6)->nullable();
        });
        Schema::create('tm_checklist_items', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('public_key')->unique(); $t->foreignId('checklist_id')->constrained('tm_checklists')->restrictOnDelete();
            $t->string('title'); $t->boolean('done')->default(false); $t->string('assignee_ref', 120)->nullable(); $t->timestamp('due_at', 6)->nullable();
            $t->foreignId('converted_task_id')->nullable()->unique()->constrained('tm_tasks')->restrictOnDelete(); $t->integer('position')->default(0);
            $t->unsignedInteger('version')->default(1); $t->timestamp('deleted_at', 6)->nullable(); $t->index(['assignee_ref', 'due_at', 'id'], 'tm_checklist_due_assignee');
        });
        Schema::create('tm_attachment_tickets', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->string('token_hash', 64)->unique(); $t->string('scope_key', 120); $t->foreignId('attachment_id')->constrained('tm_attachments')->restrictOnDelete();
            $t->unsignedInteger('attachment_version'); $t->string('actor_ref', 120); $t->timestamp('expires_at', 6); $t->timestamp('revoked_at', 6)->nullable();
            $t->index(['scope_key', 'actor_ref', 'attachment_id', 'expires_at'], 'tm_file_ticket_actor');
        });
    }
    public function down(): void
    {
        if (DB::table('tm_comment_revisions')->exists() || DB::table('tm_checklists')->exists() || DB::table('tm_attachment_tickets')->exists()) { throw new RuntimeException('collaboration_rollback_requires_export'); }
        foreach (['tm_attachment_tickets', 'tm_checklist_items', 'tm_checklists', 'tm_task_watchers', 'tm_comment_mentions', 'tm_comment_revisions'] as $table) { Schema::dropIfExists($table); }
        Schema::table('tm_comments', function (Blueprint $t) { $t->dropForeign(['parent_comment_id']); $t->dropColumn(['created_at', 'edited_at', 'parent_comment_id']); });
        Schema::table('tm_attachments', fn (Blueprint $t) => $t->dropColumn(['created_at', 'storage_verified']));
    }
};
