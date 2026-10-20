<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_handoff_groups', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('group_key')->unique(); $t->string('scope_key', 120);
            $t->foreignId('origin_id')->constrained('tm_workflow_instances')->restrictOnDelete();
            $t->text('join_policy'); $t->unsignedInteger('expected_count'); $t->string('status', 30)->default('active');
            $t->string('outcome', 30)->nullable(); $t->unsignedInteger('version')->default(1);
        });
        Schema::create('tm_handoffs', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('handoff_key')->unique(); $t->string('scope_key', 120);
            $t->foreignId('origin_id')->constrained('tm_workflow_instances')->restrictOnDelete();
            $t->foreignId('destination_id')->constrained('tm_workflow_instances')->restrictOnDelete();
            $t->unsignedBigInteger('group_id')->nullable(); $t->foreign('group_id')->references('id')->on('tm_handoff_groups')->restrictOnDelete();
            $t->unsignedInteger('origin_version'); $t->unsignedInteger('version')->default(1);
            $t->string('status', 30)->default('active'); $t->string('outcome', 30)->nullable();
            $t->text('policy_snapshot'); $t->text('assignment_snapshot'); $t->text('origin_snapshot'); $t->text('lineage');
            $t->string('initiator_ref', 120); $t->string('reason_code', 100); $t->string('target_team', 120);
            $t->unsignedBigInteger('paused_visit_id')->nullable(); $t->timestamp('deadline_at')->nullable();
            $t->string('failure_code', 100)->nullable(); $t->timestamp('created_at'); $t->timestamp('resolved_at')->nullable();
            $t->index(['scope_key', 'status', 'deadline_at']); $t->unique('destination_id');
        });
        Schema::create('tm_active_handoff_origins', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('origin_id')->primary(); $t->foreign('origin_id')->references('id')->on('tm_workflow_instances')->restrictOnDelete();
            $t->foreignId('handoff_id')->unique()->constrained('tm_handoffs')->restrictOnDelete();
        });
        Schema::create('tm_handoff_outcomes', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('handoff_id')->unique()->constrained('tm_handoffs')->restrictOnDelete();
            $t->string('outcome', 30); $t->unsignedInteger('destination_version'); $t->string('actor_ref', 120);
            $t->string('operation_id', 120); $t->timestamp('occurred_at');
        });
    }
    public function down(): void
    {
        if (DB::table('tm_handoffs')->exists()) { throw new RuntimeException('handoff_rollback_requires_export'); }
        Schema::dropIfExists('tm_handoff_outcomes'); Schema::dropIfExists('tm_active_handoff_origins'); Schema::dropIfExists('tm_handoffs');
        Schema::dropIfExists('tm_handoff_groups');
    }
};
