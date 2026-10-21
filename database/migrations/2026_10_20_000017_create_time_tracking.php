<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tm_actor_time_locks', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->primary(['scope_key', 'actor_ref']);
        });
        Schema::create('tm_task_timers', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('timer_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->string('state', 30)->default('active');
            $t->timestamp('started_at', 6);
            $t->timestamp('ended_at', 6)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->index(['scope_key', 'actor_ref', 'started_at'], 'tm_timer_actor_time');
        });
        Schema::create('tm_active_timers', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->foreignId('timer_id')->unique()->constrained('tm_task_timers')->restrictOnDelete();
            $t->primary(['scope_key', 'actor_ref']);
        });
        Schema::create('tm_task_time_entries', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->timestamp('started_at', 6);
            $t->timestamp('ended_at', 6);
            $t->unsignedBigInteger('duration_seconds');
            $t->text('note')->nullable();
            $t->foreignId('timer_id')->nullable()->unique()->constrained('tm_task_timers')->restrictOnDelete();
            $t->boolean('overlap_warning')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('deleted_at', 6)->nullable();
            $t->index(['scope_key', 'actor_ref', 'started_at', 'ended_at'], 'tm_time_actor_interval');
            $t->index(['task_id', 'deleted_at', 'id'], 'tm_time_task_totals');
        });
        Schema::table('tm_tasks', fn (Blueprint $t) => $t->unsignedBigInteger('time_revision')->default(1));
        Schema::create('tm_task_stats', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->primary()->constrained('tm_tasks')->restrictOnDelete();
            $t->unsignedBigInteger('source_revision');
            $t->unsignedInteger('source_task_version');
            $t->unsignedBigInteger('spent_seconds');
            $t->unsignedBigInteger('remaining_seconds')->nullable();
            $t->timestamp('rebuilt_at', 6);
        });
    }

    public function down(): void
    {
        if (DB::table('tm_task_timers')->exists() || DB::table('tm_task_time_entries')->exists()) {
            throw new RuntimeException('time_rollback_requires_export');
        }
        foreach (['tm_task_stats', 'tm_task_time_entries', 'tm_active_timers', 'tm_task_timers', 'tm_actor_time_locks'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('tm_tasks', fn (Blueprint $t) => $t->dropColumn('time_revision'));
    }
};
