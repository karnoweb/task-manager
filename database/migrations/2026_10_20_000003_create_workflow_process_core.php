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
        if (DB::table(Tables::name('tasks'))->whereNotNull('project_id')->exists()) {
            throw new RuntimeException('legacy_project_mapping_required: phase-one project placeholders must be mapped before upgrade');
        }
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->dropColumn('project_id'));
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->unsignedBigInteger('project_id')->nullable());
        Schema::create(Tables::name('workflow_heads'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('key', 120);
            $t->unsignedInteger('revision')->default(0);
            $t->primary(['scope_key', 'key']);
        });
        Schema::create(Tables::name('projects'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('project_key')->unique();
            $t->string('scope_key', 120);
            $t->string('key', 80);
            $t->string('name');
            $t->string('owner_ref', 120);
            $t->foreignId('workflow_revision_id')->constrained(Tables::name('workflow_definitions'))->restrictOnDelete();
            $t->string('status')->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->unique(['scope_key', 'key']);
        });
        Schema::create(Tables::name('project_members'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('project_id');
            $t->string('actor_ref', 120);
            $t->string('role', 30);
            $t->foreign('project_id')->references('id')->on(Tables::name('projects'))->restrictOnDelete();
            $t->primary(['project_id', 'actor_ref']);
        });
        Schema::create(Tables::name('project_type_workflows'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('project_id');
            $t->string('type_key', 100);
            $t->foreignId('workflow_revision_id')->constrained(Tables::name('workflow_definitions'))->restrictOnDelete();
            $t->foreign('project_id')->references('id')->on(Tables::name('projects'))->restrictOnDelete();
            $t->primary(['project_id', 'type_key']);
        });
        Schema::create(Tables::name('pipelines'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('pipeline_key')->unique();
            $t->string('scope_key', 120);
            $t->string('name', 120);
            $t->string('owner_ref', 120);
            $t->foreignId('workflow_revision_id')->constrained(Tables::name('workflow_definitions'))->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->unique(['scope_key', 'name']);
        });
        Schema::create(Tables::name('pipeline_stages'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('pipeline_id')->constrained(Tables::name('pipelines'))->restrictOnDelete();
            $t->string('stage_key', 100);
            $t->string('state_key', 100);
            $t->unsignedInteger('capacity')->nullable();
            $t->unique(['pipeline_id', 'stage_key']);
        });
        Schema::create(Tables::name('process_cases'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('case_key')->unique();
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->string('title');
            $t->unsignedInteger('version')->default(1);
        });
        Schema::table(Tables::name('tasks'), function (Blueprint $t) {
            $t->string('type_key', 100)->default('task');
            $t->unsignedBigInteger('parent_task_id')->nullable();
            $t->foreign('parent_task_id')->references('id')->on(Tables::name('tasks'))->restrictOnDelete();
            $t->foreign('project_id')->references('id')->on(Tables::name('projects'))->restrictOnDelete();
        });
        Schema::table(Tables::name('workflow_instances'), fn (Blueprint $t) => $t->index('task_id', Tables::name('instance_task_lookup')));
        Schema::table(Tables::name('workflow_instances'), function (Blueprint $t) {
            $t->dropUnique(['task_id']);
            $t->unsignedBigInteger('task_id')->nullable()->change();
            $t->string('subject_type', 100)->default('task');
            $t->string('subject_id', 120)->nullable();
            $t->string('slot_key', 100)->default('main');
            $t->string('owner_ref', 120)->nullable();
            $t->string('primary_assignee', 120)->nullable();
            $t->string('lifecycle', 30)->default('active');
            $t->unsignedBigInteger('pipeline_id')->nullable();
            $t->string('active_stage_key', 100)->nullable();
            $t->unsignedBigInteger('case_id')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->foreign('pipeline_id')->references('id')->on(Tables::name('pipelines'))->restrictOnDelete();
            $t->foreign('case_id')->references('id')->on(Tables::name('process_cases'))->restrictOnDelete();
            $t->index(['scope_key', 'subject_type', 'subject_id']);
            $t->index(['scope_key', 'pipeline_id', 'active_stage_key', 'lifecycle'], Tables::name('instance_stage_lifecycle'));
        });
        Schema::create(Tables::name('active_process_slots'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('subject_type', 100);
            $t->string('subject_id', 120);
            $t->string('slot_key', 100);
            $t->foreignId('instance_id')->unique()->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->primary(['scope_key', 'subject_type', 'subject_id', 'slot_key'], Tables::name('active_slot_pk'));
        });
        Schema::create(Tables::name('case_subjects'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('case_id')->constrained(Tables::name('process_cases'))->restrictOnDelete();
            $t->string('subject_type', 100);
            $t->string('subject_id', 120);
            $t->primary(['case_id', 'subject_type', 'subject_id']);
        });
        Schema::create(Tables::name('stage_visits'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->foreignId('instance_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('pipeline_id')->constrained(Tables::name('pipelines'))->restrictOnDelete();
            $t->string('stage_key', 100);
            $t->unsignedInteger('visit_no');
            $t->dateTime('entered_at');
            $t->dateTime('exited_at')->nullable();
            $t->unique(['instance_id', 'visit_no']);
        });
        Schema::create(Tables::name('security_decisions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->string('action', 80);
            $t->string('reason', 80);
            $t->string('target_hash', 64);
            $t->dateTime('occurred_at');
        });
        foreach ([Tables::name('audit_events'), Tables::name('outbox')] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('aggregate_type', 50)->default('task');
                $t->string('aggregate_key', 120)->nullable();
            });
        }
        DB::table(Tables::name('workflow_instances'))->orderBy('id')->chunkById(250, function ($instances) {
            foreach ($instances as $i) {
                $task = DB::table(Tables::name('tasks'))->where('id', $i->task_id)->first();
                DB::table(Tables::name('workflow_instances'))->where('id', $i->id)->update(['subject_id' => $task->public_key, 'owner_ref' => $task->owner_ref,
                    'primary_assignee' => $task->owner_ref, 'lifecycle' => $i->current_state_key === 'done' ? 'closed' : 'active', 'started_at' => $task->created_at]);
                if ($i->current_state_key !== 'done') {
                    DB::table(Tables::name('active_process_slots'))->insert(['scope_key' => $i->scope_key, 'subject_type' => 'task', 'subject_id' => $task->public_key, 'slot_key' => 'main', 'instance_id' => $i->id]);
                }
            }
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('workflow_instances'))->whereNull('task_id')->orWhereNotNull('pipeline_id')->orWhere('slot_key', '!=', 'main')->exists()
            || DB::table(Tables::name('tasks'))->whereNotNull('project_id')->exists() || DB::table(Tables::name('process_cases'))->exists()) {
            throw new RuntimeException('process_rollback_requires_export: downgrade would discard process identity; export/migrate those records first');
        }
        Schema::dropIfExists(Tables::name('security_decisions'));
        foreach ([Tables::name('stage_visits'), Tables::name('case_subjects'), Tables::name('active_process_slots')] as $table) {
            Schema::dropIfExists($table);
        }
        foreach ([Tables::name('audit_events'), Tables::name('outbox')] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['aggregate_type', 'aggregate_key']));
        }
        Schema::table(Tables::name('workflow_instances'), function (Blueprint $t) {
            $t->dropForeign(['pipeline_id']);
            $t->dropForeign(['case_id']);
            $t->dropIndex(Tables::name('instance_stage_lifecycle'));
            $t->dropIndex(['scope_key', 'subject_type', 'subject_id']);
            $t->dropColumn(['subject_type', 'subject_id', 'slot_key', 'owner_ref', 'primary_assignee', 'lifecycle', 'pipeline_id', 'active_stage_key', 'case_id', 'started_at']);
        });
        Schema::table(Tables::name('tasks'), function (Blueprint $t) {
            $t->dropForeign(['parent_task_id']);
            $t->dropForeign(['project_id']);
            $t->dropColumn(['type_key', 'parent_task_id']);
        });
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->dropColumn('project_id'));
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->uuid('project_id')->nullable());
        foreach ([Tables::name('process_cases'), Tables::name('pipeline_stages'), Tables::name('pipelines'), Tables::name('project_type_workflows'), Tables::name('project_members'), Tables::name('projects'), Tables::name('workflow_heads')] as $table) {
            Schema::dropIfExists($table);
        }
        // External/multiple-slot history cannot be losslessly represented by the old schema.
        // Keep nullable task_id and the relaxed unique constraint; no historic data is discarded.
    }
};
