<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('comments'), function (Blueprint $t) {
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('edited_at', 6)->nullable();
            $t->foreignId('parent_comment_id')->nullable()->constrained(Tables::name('comments'))->restrictOnDelete();
        });
        Schema::table(Tables::name('attachments'), function (Blueprint $t) {
            $t->dateTime('created_at', 6)->nullable();
            $t->boolean('storage_verified')->default(false);
        });
        Schema::create(Tables::name('comment_revisions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('comment_id')->constrained(Tables::name('comments'))->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->text('body');
            $t->string('editor_ref', 120);
            $t->dateTime('occurred_at', 6)->nullable();
            $t->boolean('deleted')->default(false);
            $t->unique(['comment_id', 'version']);
        });
        DB::table(Tables::name('comments'))->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                DB::table(Tables::name('comment_revisions'))->insert(['comment_id' => $row->id, 'version' => $row->version, 'body' => $row->body, 'editor_ref' => $row->author_ref, 'occurred_at' => null, 'deleted' => $row->deleted_at !== null]);
            }
        });
        Schema::create(Tables::name('comment_mentions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('comment_id')->constrained(Tables::name('comments'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->primary(['comment_id', 'actor_ref']);
        });
        Schema::create(Tables::name('task_watchers'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->dateTime('created_at', 6);
            $t->primary(['task_id', 'actor_ref']);
        });
        Schema::create(Tables::name('checklists'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('title');
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 6);
            $t->dateTime('deleted_at', 6)->nullable();
        });
        Schema::create(Tables::name('checklist_items'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->foreignId('checklist_id')->constrained(Tables::name('checklists'))->restrictOnDelete();
            $t->string('title');
            $t->boolean('done')->default(false);
            $t->string('assignee_ref', 120)->nullable();
            $t->dateTime('due_at', 6)->nullable();
            $t->foreignId('converted_task_id')->nullable()->unique()->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->integer('position')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('deleted_at', 6)->nullable();
            $t->index(['assignee_ref', 'due_at', 'id'], Tables::name('checklist_due_assignee'));
        });
        Schema::create(Tables::name('attachment_tickets'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('token_hash', 64)->unique();
            $t->string('scope_key', 120);
            $t->foreignId('attachment_id')->constrained(Tables::name('attachments'))->restrictOnDelete();
            $t->unsignedInteger('attachment_version');
            $t->string('actor_ref', 120);
            $t->dateTime('expires_at', 6);
            $t->dateTime('revoked_at', 6)->nullable();
            $t->index(['scope_key', 'actor_ref', 'attachment_id', 'expires_at'], Tables::name('file_ticket_actor'));
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('comment_revisions'))->exists() || DB::table(Tables::name('checklists'))->exists() || DB::table(Tables::name('attachment_tickets'))->exists()) {
            throw new RuntimeException('collaboration_rollback_requires_export');
        }
        foreach ([Tables::name('attachment_tickets'), Tables::name('checklist_items'), Tables::name('checklists'), Tables::name('task_watchers'), Tables::name('comment_mentions'), Tables::name('comment_revisions')] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table(Tables::name('comments'), function (Blueprint $t) {
            $t->dropForeign(['parent_comment_id']);
            $t->dropColumn(['created_at', 'edited_at', 'parent_comment_id']);
        });
        Schema::table(Tables::name('attachments'), fn (Blueprint $t) => $t->dropColumn(['created_at', 'storage_verified']));
    }
};
