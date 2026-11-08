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
        Schema::create(Tables::name('handoff_groups'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('group_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('origin_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->text('join_policy');
            $t->unsignedInteger('expected_count');
            $t->string('status', 30)->default('active');
            $t->string('outcome', 30)->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create(Tables::name('handoffs'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('handoff_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('origin_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('destination_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->foreign('group_id')->references('id')->on(Tables::name('handoff_groups'))->restrictOnDelete();
            $t->unsignedInteger('origin_version');
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 30)->default('active');
            $t->string('outcome', 30)->nullable();
            $t->text('policy_snapshot');
            $t->text('assignment_snapshot');
            $t->text('origin_snapshot');
            $t->text('lineage');
            $t->string('initiator_ref', 120);
            $t->string('reason_code', 100);
            $t->string('target_team', 120);
            $t->unsignedBigInteger('paused_visit_id')->nullable();
            $t->dateTime('deadline_at')->nullable();
            $t->string('failure_code', 100)->nullable();
            $t->dateTime('created_at');
            $t->dateTime('resolved_at')->nullable();
            $t->index(['scope_key', 'status', 'deadline_at']);
            $t->unique('destination_id');
        });
        Schema::create(Tables::name('active_handoff_origins'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('origin_id')->primary();
            $t->foreign('origin_id')->references('id')->on(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('handoff_id')->unique()->constrained(Tables::name('handoffs'))->restrictOnDelete();
        });
        Schema::create(Tables::name('handoff_outcomes'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('handoff_id')->unique()->constrained(Tables::name('handoffs'))->restrictOnDelete();
            $t->string('outcome', 30);
            $t->unsignedInteger('destination_version');
            $t->string('actor_ref', 120);
            $t->string('operation_id', 120);
            $t->dateTime('occurred_at');
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('handoffs'))->exists()) {
            throw new RuntimeException('handoff_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('handoff_outcomes'));
        Schema::dropIfExists(Tables::name('active_handoff_origins'));
        Schema::dropIfExists(Tables::name('handoffs'));
        Schema::dropIfExists(Tables::name('handoff_groups'));
    }
};
