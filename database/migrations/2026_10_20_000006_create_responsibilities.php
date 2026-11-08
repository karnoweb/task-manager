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
        Schema::create(Tables::name('assignment_policies'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('stage_id')->nullable();
            $t->foreign('stage_id')->references('id')->on(Tables::name('pipeline_stages'))->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->text('definition');
            $t->unique(['stage_id', 'revision']);
        });
        foreach ([Tables::name('pipeline_stages'), Tables::name('workflow_instances'), Tables::name('stage_visits')] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('assignment_policy_id')->nullable();
                $t->foreign('assignment_policy_id')->references('id')->on(Tables::name('assignment_policies'))->restrictOnDelete();
            });
        }
        Schema::table(Tables::name('workflow_instances'), fn (Blueprint $t) => $t->text('assignment_snapshot')->nullable());
        Schema::create(Tables::name('instance_assignments'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->foreignId('instance_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->string('kind', 30);
            $t->dateTime('active_from');
            $t->dateTime('active_to')->nullable();
            $t->string('assigned_by', 120);
            $t->string('reason_code', 100);
            $t->unsignedBigInteger('policy_id')->nullable();
            $t->unsignedBigInteger('visit_id')->nullable();
            $t->foreign('policy_id')->references('id')->on(Tables::name('assignment_policies'))->restrictOnDelete();
            $t->foreign('visit_id')->references('id')->on(Tables::name('stage_visits'))->restrictOnDelete();
            $t->index(['scope_key', 'actor_ref', 'active_to'], Tables::name('actor_assignment_history'));
        });
        Schema::create(Tables::name('assignment_slots'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('instance_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->string('slot_key', 80);
            $t->string('actor_ref', 120);
            $t->foreignId('assignment_id')->unique()->constrained(Tables::name('instance_assignments'))->restrictOnDelete();
            $t->primary(['instance_id', 'slot_key']);
            $t->unique(['instance_id', 'actor_ref']);
        });
        Schema::create(Tables::name('assignment_queues'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('reference_key', 64);
            $t->unsignedBigInteger('position')->default(0);
            $t->primary(['scope_key', 'reference_key']);
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('assignment_policies'))->exists() || DB::table(Tables::name('instance_assignments'))->exists()) {
            throw new RuntimeException('assignment_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('assignment_queues'));
        Schema::dropIfExists(Tables::name('assignment_slots'));
        Schema::dropIfExists(Tables::name('instance_assignments'));
        Schema::table(Tables::name('workflow_instances'), fn (Blueprint $t) => $t->dropColumn('assignment_snapshot'));
        foreach ([Tables::name('pipeline_stages'), Tables::name('workflow_instances'), Tables::name('stage_visits')] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['assignment_policy_id']);
                $t->dropColumn('assignment_policy_id');
            });
        }
        Schema::dropIfExists(Tables::name('assignment_policies'));
    }
};
