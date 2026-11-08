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
        Schema::create(Tables::name('task_dependencies'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('predecessor_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->foreignId('successor_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('type', 30);
            $t->boolean('blocking');
            $t->integer('lag_minutes')->default(0);
            $t->string('clock', 30)->default('elapsed');
            $t->string('calendar_ref', 120)->nullable();
            $t->string('enforcement', 30)->default('strict');
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 6);
            $t->dateTime('deleted_at', 6)->nullable();
            $t->index(['scope_key', 'predecessor_id', 'blocking', 'deleted_at'], Tables::name('dependency_outgoing'));
            $t->index(['scope_key', 'successor_id', 'blocking', 'deleted_at'], Tables::name('dependency_incoming'));
        });
        Schema::create(Tables::name('active_dependencies'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('predecessor_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->foreignId('successor_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('type', 30);
            $t->foreignId('dependency_id')->unique()->constrained(Tables::name('task_dependencies'))->restrictOnDelete();
            $t->primary(['predecessor_id', 'successor_id', 'type'], Tables::name('active_dependency_key'));
        });
        Schema::table(Tables::name('tasks'), function (Blueprint $t) {
            $t->dateTime('planned_start_at', 6)->nullable();
            $t->dateTime('planned_finish_at', 6)->nullable();
            $t->unsignedInteger('estimate_minutes')->nullable();
            $t->decimal('story_points', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('task_dependencies'))->exists() || DB::table(Tables::name('tasks'))->whereNotNull('planned_start_at')->orWhereNotNull('estimate_minutes')->orWhereNotNull('story_points')->exists()) {
            throw new RuntimeException('dependency_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('active_dependencies'));
        Schema::dropIfExists(Tables::name('task_dependencies'));
        Schema::table(Tables::name('tasks'), fn (Blueprint $t) => $t->dropColumn(['planned_start_at', 'planned_finish_at', 'estimate_minutes', 'story_points']));
    }
};
