<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tm_lists', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->string('name');
            $t->unique(['scope_key', 'owner_ref', 'name']);
        });
        Schema::create('tm_tasks', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->string('display_key');
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->unsignedBigInteger('list_id');
            $t->uuid('project_id')->nullable();
            $t->string('title', 255);
            $t->string('title_search_normalized', 255);
            $t->text('description')->nullable();
            $t->string('priority')->default('normal');
            $t->decimal('rank', 20, 8)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->index(['scope_key', 'owner_ref']);
            $t->unique(['scope_key', 'display_key']);
            $t->index(['scope_key', 'title_search_normalized']);
            $t->foreign('list_id')->references('id')->on('tm_lists')->restrictOnDelete();
        });
        Schema::create('tm_workflow_instances', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('instance_key')->unique();
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('task_id')->unique();
            $t->foreign('task_id')->references('id')->on('tm_tasks')->restrictOnDelete();
            $t->string('workflow_key')->default('personal');
            $t->unsignedBigInteger('workflow_revision_id');
            $t->string('current_state_key')->default('pending');
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('tm_workflow_definitions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('key', 120);
            $t->unsignedInteger('revision')->default(1);
            $t->text('definition');
            $t->unique(['scope_key', 'key', 'revision']);
        });
        Schema::table('tm_workflow_instances', function (Blueprint $t) {
            $t->foreign('workflow_revision_id')->references('id')->on('tm_workflow_definitions')->restrictOnDelete();
        });
        Schema::create('tm_task_grants', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('task_id');
            $t->foreign('task_id')->references('id')->on('tm_tasks')->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->string('capability', 30);
            $t->primary(['task_id', 'actor_ref', 'capability']);
        });
        Schema::create('tm_instance_transitions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('instance_id');
            $t->foreign('instance_id')->references('id')->on('tm_workflow_instances')->restrictOnDelete();
            $t->string('from_state');
            $t->string('to_state');
            $t->string('actor_ref', 120);
            $t->string('operation_id', 120);
            $t->timestamp('occurred_at');
            $t->index(['scope_key', 'instance_id']);
        });
        Schema::create('tm_command_journal', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('operation_id', 120);
            $t->string('actor_ref', 120);
            $t->string('payload_hash', 64);
            $t->text('result');
            $t->string('status')->default('pending');
            $t->primary(['scope_key', 'operation_id']);
        });
        Schema::create('tm_sequences', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('sequence_key', 120);
            $t->unsignedBigInteger('current')->default(0);
            $t->primary(['scope_key', 'sequence_key']);
        });
        Schema::create('tm_task_key_aliases', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('task_id');
            $t->string('display_key');
            $t->boolean('is_current')->default(true);
            $t->unique(['scope_key', 'display_key']);
            $t->foreign('task_id')->references('id')->on('tm_tasks')->restrictOnDelete();
        });
        foreach (['tm_audit_events', 'tm_outbox'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
                $t->bigIncrements('id');
                $t->uuid('event_id')->unique();
                $t->string('scope_key', 120);
                $t->string('actor_ref', 120);
                $t->unsignedBigInteger('task_id')->nullable();
                $t->foreign('task_id')->references('id')->on('tm_tasks')->restrictOnDelete();
                $t->string('action');
                $t->string('operation_id', 120);
                $t->string('correlation_id', 120);
                $t->text('payload');
                $t->timestamp('occurred_at');
                if ($name === 'tm_outbox') {
                    $t->unsignedInteger('attempts')->default(0);
                    $t->timestamp('published_at')->nullable();
                }
                $t->index(['scope_key', 'task_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['tm_outbox', 'tm_audit_events', 'tm_task_key_aliases', 'tm_sequences', 'tm_command_journal', 'tm_instance_transitions', 'tm_task_grants', 'tm_workflow_instances', 'tm_workflow_definitions', 'tm_tasks', 'tm_lists'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
