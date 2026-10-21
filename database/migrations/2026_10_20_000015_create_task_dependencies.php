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
        Schema::create('tm_task_dependencies', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('public_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('predecessor_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->foreignId('successor_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('type', 30);
            $t->boolean('blocking');
            $t->integer('lag_minutes')->default(0);
            $t->string('clock', 30)->default('elapsed');
            $t->string('calendar_ref', 120)->nullable();
            $t->string('enforcement', 30)->default('strict');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('created_at', 6);
            $t->timestamp('deleted_at', 6)->nullable();
            $t->index(['scope_key', 'predecessor_id', 'blocking', 'deleted_at'], 'tm_dependency_outgoing');
            $t->index(['scope_key', 'successor_id', 'blocking', 'deleted_at'], 'tm_dependency_incoming');
        });
        Schema::create('tm_active_dependencies', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('predecessor_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->foreignId('successor_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('type', 30);
            $t->foreignId('dependency_id')->unique()->constrained('tm_task_dependencies')->restrictOnDelete();
            $t->primary(['predecessor_id', 'successor_id', 'type'], 'tm_active_dependency_key');
        });
        Schema::table('tm_tasks', function (Blueprint $t) {
            $t->timestamp('planned_start_at', 6)->nullable();
            $t->timestamp('planned_finish_at', 6)->nullable();
            $t->unsignedInteger('estimate_minutes')->nullable();
            $t->decimal('story_points', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('tm_task_dependencies')->exists() || DB::table('tm_tasks')->whereNotNull('planned_start_at')->orWhereNotNull('estimate_minutes')->orWhereNotNull('story_points')->exists()) {
            throw new RuntimeException('dependency_rollback_requires_export');
        }
        Schema::dropIfExists('tm_active_dependencies');
        Schema::dropIfExists('tm_task_dependencies');
        Schema::table('tm_tasks', fn (Blueprint $t) => $t->dropColumn(['planned_start_at', 'planned_finish_at', 'estimate_minutes', 'story_points']));
    }
};
