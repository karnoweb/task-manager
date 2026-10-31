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
        Schema::create(Tables::name('approval_policies'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('stage_id')->nullable();
            $t->foreign('stage_id')->references('id')->on(Tables::name('pipeline_stages'))->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->text('definition');
            $t->unique(['stage_id', 'revision']);
        });
        Schema::table(Tables::name('pipeline_stages'), function (Blueprint $t) {
            $t->unsignedBigInteger('approval_policy_id')->nullable();
            $t->foreign('approval_policy_id')->references('id')->on(Tables::name('approval_policies'))->restrictOnDelete();
        });
        Schema::create(Tables::name('approval_steps'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('step_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('instance_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('policy_id')->constrained(Tables::name('approval_policies'))->restrictOnDelete();
            $t->unsignedBigInteger('visit_id')->nullable();
            $t->foreign('visit_id')->references('id')->on(Tables::name('stage_visits'))->restrictOnDelete();
            $t->string('state', 30)->default('pending');
            $t->string('execution_state', 30)->default('none');
            $t->string('requested_by', 120);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('created_at');
            $t->timestamp('decided_at')->nullable();
            $t->unsignedInteger('decision_instance_version')->nullable();
        });
        Schema::create(Tables::name('active_approval_steps'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('instance_id')->primary();
            $t->foreign('instance_id')->references('id')->on(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('step_id')->unique()->constrained(Tables::name('approval_steps'))->restrictOnDelete();
        });
        Schema::create(Tables::name('approval_votes'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('step_id')->constrained(Tables::name('approval_steps'))->restrictOnDelete();
            $t->string('actor_ref', 120);
            $t->string('vote', 30);
            $t->string('reason_code', 100)->nullable();
            $t->string('operation_id', 120);
            $t->timestamp('occurred_at');
            $t->unique(['step_id', 'actor_ref']);
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('approval_policies'))->exists() || DB::table(Tables::name('approval_steps'))->exists()) {
            throw new RuntimeException('approval_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('approval_votes'));
        Schema::dropIfExists(Tables::name('active_approval_steps'));
        Schema::dropIfExists(Tables::name('approval_steps'));
        Schema::table(Tables::name('pipeline_stages'), function (Blueprint $t) {
            $t->dropForeign(['approval_policy_id']);
            $t->dropColumn('approval_policy_id');
        });
        Schema::dropIfExists(Tables::name('approval_policies'));
    }
};
