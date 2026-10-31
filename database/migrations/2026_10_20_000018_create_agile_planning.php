<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Planning\PointValue;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->unsignedBigInteger('story_points_minor')->nullable());
        DB::table(Tables::name('tasks'))->whereNotNull('story_points')->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                DB::table(Tables::name('tasks'))->where('id', $row->id)->update(['story_points_minor' => PointValue::minor(number_format((float) $row->story_points, 2, '.', ''))]);
            }
        });
        Schema::create(Tables::name('sprints'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('sprint_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('project_id')->constrained(Tables::name('projects'))->restrictOnDelete();
            $t->string('name');
            $t->string('state', 30)->default('planned');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('planned_start_at', 6)->nullable();
            $t->timestamp('planned_end_at', 6)->nullable();
            $t->timestamp('started_at', 6)->nullable();
            $t->timestamp('closed_at', 6)->nullable();
            $t->index(['scope_key', 'project_id', 'state', 'id'], Tables::name('sprint_project_state'));
        });
        Schema::create(Tables::name('sprint_tasks'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('sprint_id')->constrained(Tables::name('sprints'))->restrictOnDelete();
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->timestamp('joined_at', 6);
            $t->timestamp('left_at', 6)->nullable();
            $t->unsignedBigInteger('points_minor_at_join')->nullable();
            $t->unsignedInteger('membership_revision');
            $t->index(['sprint_id', 'left_at', 'id'], Tables::name('sprint_members_at_boundary'));
        });
        Schema::create(Tables::name('sprint_memberships'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('sprint_id')->constrained(Tables::name('sprints'))->restrictOnDelete();
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->foreignId('membership_id')->unique()->constrained(Tables::name('sprint_tasks'))->restrictOnDelete();
            $t->primary(['sprint_id', 'task_id']);
        });
        Schema::create(Tables::name('active_sprint_tasks'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->primary()->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->foreignId('sprint_id')->constrained(Tables::name('sprints'))->restrictOnDelete();
            $t->foreignId('membership_id')->unique()->constrained(Tables::name('sprint_tasks'))->restrictOnDelete();
        });
        Schema::create(Tables::name('sprint_daily_snapshots'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('snapshot_key')->unique();
            $t->foreignId('sprint_id')->constrained(Tables::name('sprints'))->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('date_key', 10);
            $t->timestamp('captured_at', 6);
            $t->unsignedInteger('membership_revision');
            $t->unique(['sprint_id', 'kind', 'date_key'], Tables::name('sprint_snapshot_dedupe'));
        });
        Schema::create(Tables::name('sprint_snapshot_items'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('snapshot_id')->constrained(Tables::name('sprint_daily_snapshots'))->restrictOnDelete();
            $t->foreignId('membership_id')->constrained(Tables::name('sprint_tasks'))->restrictOnDelete();
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->unsignedInteger('task_version');
            $t->string('category', 30);
            $t->boolean('is_leaf');
            $t->unsignedBigInteger('points_minor')->nullable();
            $t->primary(['snapshot_id', 'task_id']);
        });
        Schema::create(Tables::name('project_markers'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('marker_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('project_id')->constrained(Tables::name('projects'))->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('name');
            $t->timestamp('due_at', 6)->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create(Tables::name('marker_tasks'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('marker_id')->constrained(Tables::name('project_markers'))->restrictOnDelete();
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->primary(['marker_id', 'task_id']);
        });
        Schema::create(Tables::name('task_position_contexts'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('context_key')->unique();
            $t->string('scope_key', 120);
            $t->string('kind', 30);
            $t->string('context_ref', 120);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['scope_key', 'kind', 'context_ref'], Tables::name('position_context_unique'));
        });
        Schema::create(Tables::name('task_positions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('context_id')->constrained(Tables::name('task_position_contexts'))->restrictOnDelete();
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->bigInteger('rank_units');
            $t->unsignedInteger('version')->default(1);
            $t->unique(['context_id', 'task_id']);
            $t->index(['context_id', 'rank_units', 'task_id'], Tables::name('position_keyset'));
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('sprints'))->exists() || DB::table(Tables::name('project_markers'))->exists() || DB::table(Tables::name('task_position_contexts'))->exists()) {
            throw new RuntimeException('agile_rollback_requires_export');
        }
        foreach ([Tables::name('task_positions'), Tables::name('task_position_contexts'), Tables::name('marker_tasks'), Tables::name('project_markers'), Tables::name('sprint_snapshot_items'), Tables::name('sprint_daily_snapshots'), Tables::name('active_sprint_tasks'), Tables::name('sprint_memberships'), Tables::name('sprint_tasks'), Tables::name('sprints')] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->dropColumn('story_points_minor'));
    }
};
