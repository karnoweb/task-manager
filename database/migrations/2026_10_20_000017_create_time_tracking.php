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
        Schema::create(Tables::name('actor_time_locks'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->primary(['scope_key', 'actor_ref']);
        });
        Schema::create(Tables::name('task_timers'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('timer_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->string('state', 30)->default('active');
            $t->timestamp('started_at', 6);
            $t->timestamp('ended_at', 6)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->index(['scope_key', 'actor_ref', 'started_at'], Tables::name('timer_actor_time'));
        });
        Schema::create(Tables::name('active_timers'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->foreignId('timer_id')->unique()->constrained(Tables::name('task_timers'))->restrictOnDelete();
            $t->primary(['scope_key', 'actor_ref']);
        });
        Schema::create(Tables::name('task_time_entries'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->timestamp('started_at', 6);
            $t->timestamp('ended_at', 6);
            $t->unsignedBigInteger('duration_seconds');
            $t->text('note')->nullable();
            $t->foreignId('timer_id')->nullable()->unique()->constrained(Tables::name('task_timers'))->restrictOnDelete();
            $t->boolean('overlap_warning')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('deleted_at', 6)->nullable();
            $t->index(['scope_key', 'actor_ref', 'started_at', 'ended_at'], Tables::name('time_actor_interval'));
            $t->index(['task_id', 'deleted_at', 'id'], Tables::name('time_task_totals'));
        });
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->unsignedBigInteger('time_revision')->default(1));
        Schema::create(Tables::name('task_stats'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->primary()->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->unsignedBigInteger('source_revision');
            $t->unsignedInteger('source_task_version');
            $t->unsignedBigInteger('spent_seconds');
            $t->unsignedBigInteger('remaining_seconds')->nullable();
            $t->timestamp('rebuilt_at', 6);
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('task_timers'))->exists() || DB::table(Tables::name('task_time_entries'))->exists()) {
            throw new RuntimeException('time_rollback_requires_export');
        }
        foreach ([Tables::name('task_stats'), Tables::name('task_time_entries'), Tables::name('active_timers'), Tables::name('task_timers'), Tables::name('actor_time_locks')] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->dropColumn('time_revision'));
    }
};
