<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Tables::assertConnection(Schema::getConnection());
        Schema::create(Tables::name('lists'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->string('name');
            $t->unique(['scope_key', 'owner_ref', 'name']);
        });
        Schema::create(Tables::name('tasks'), function (Blueprint $t) {
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
            $t->dateTime('archived_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->timestamps();
            $t->index(['scope_key', 'owner_ref']);
            $t->unique(['scope_key', 'display_key']);
            $t->index(['scope_key', 'title_search_normalized']);
            $t->foreign('list_id')->references('id')->on(Tables::name('lists'))->restrictOnDelete();
        });
        Schema::create(Tables::name('workflow_instances'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('instance_key')->unique();
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('task_id')->unique();
            $t->foreign('task_id')->references('id')->on(Tables::name('tasks'))->restrictOnDelete();
            $t->string('workflow_key')->default('personal');
            $t->unsignedBigInteger('workflow_revision_id');
            $t->string('current_state_key')->default('pending');
            $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create(Tables::name('workflow_definitions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('key', 120);
            $t->unsignedInteger('revision')->default(1);
            $t->text('definition');
            $t->unique(['scope_key', 'key', 'revision']);
        });
        Schema::table(Tables::name('workflow_instances'), function (Blueprint $t) {
            $t->foreign('workflow_revision_id')->references('id')->on(Tables::name('workflow_definitions'))->restrictOnDelete();
        });
        Schema::create(Tables::name('task_grants'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('task_id');
            $t->foreign('task_id')->references('id')->on(Tables::name('tasks'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->string('capability', 30);
            $t->primary(['task_id', 'actor_ref', 'capability']);
        });
        Schema::create(Tables::name('instance_transitions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('instance_id');
            $t->foreign('instance_id')->references('id')->on(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->string('from_state');
            $t->string('to_state');
            $t->string('actor_ref', 120);
            $t->string('operation_id', 120);
            $t->dateTime('occurred_at');
            $t->index(['scope_key', 'instance_id']);
        });
        Schema::create(Tables::name('command_journal'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('operation_id', 120);
            $t->string('actor_ref', 120);
            $t->string('payload_hash', 64);
            $t->text('result');
            $t->string('status')->default('pending');
            $t->primary(['scope_key', 'operation_id']);
        });
        Schema::create(Tables::name('sequences'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('sequence_key', 120);
            $t->unsignedBigInteger('current')->default(0);
            $t->primary(['scope_key', 'sequence_key']);
        });
        Schema::create(Tables::name('task_key_aliases'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('task_id');
            $t->string('display_key');
            $t->boolean('is_current')->default(true);
            $t->unique(['scope_key', 'display_key']);
            $t->foreign('task_id')->references('id')->on(Tables::name('tasks'))->restrictOnDelete();
        });
        foreach ([Tables::name('audit_events'), Tables::name('outbox')] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
                $t->bigIncrements('id');
                $t->uuid('event_id')->unique();
                $t->string('scope_key', 120);
                $t->string('actor_ref', 120);
                $t->unsignedBigInteger('task_id')->nullable();
                $t->foreign('task_id')->references('id')->on(Tables::name('tasks'))->restrictOnDelete();
                $t->string('action');
                $t->string('operation_id', 120);
                $t->string('correlation_id', 120);
                $t->text('payload');
                $t->dateTime('occurred_at');
                if ($name === Tables::name('outbox')) {
                    $t->unsignedInteger('attempts')->default(0);
                    $t->dateTime('published_at')->nullable();
                }
                $t->index(['scope_key', 'task_id']);
            });
        }
        IdentifierCollation::align(Schema::getConnection(), Tables::name('outbox'), 'event_id');
        IdentifierCollation::align(Schema::getConnection(), Tables::name('audit_events'), 'event_id');
    }

    public function down(): void
    {
        foreach ([Tables::name('outbox'), Tables::name('audit_events'), Tables::name('task_key_aliases'), Tables::name('sequences'), Tables::name('command_journal'), Tables::name('instance_transitions'), Tables::name('task_grants'), Tables::name('workflow_instances'), Tables::name('workflow_definitions'), Tables::name('tasks'), Tables::name('lists')] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
