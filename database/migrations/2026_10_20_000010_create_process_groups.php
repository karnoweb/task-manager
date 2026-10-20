<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_process_groups', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('group_key')->unique(); $t->string('scope_key', 120); $t->string('source_type', 100); $t->string('source_id', 120);
            $t->string('owner_ref', 120); $t->string('state', 30)->default('open'); $t->unsignedInteger('version')->default(1); $t->unsignedBigInteger('policy_id')->nullable();
            $t->index(['scope_key', 'source_type', 'source_id']);
        });
        Schema::create('tm_group_lifecycle_policies', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('group_id')->constrained('tm_process_groups')->restrictOnDelete(); $t->unsignedInteger('revision'); $t->text('definition'); $t->unique(['group_id', 'revision']);
        });
        Schema::table('tm_process_groups', fn (Blueprint $t) => $t->foreign('policy_id')->references('id')->on('tm_group_lifecycle_policies')->restrictOnDelete());
        Schema::create('tm_process_group_members', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('group_id')->constrained('tm_process_groups')->restrictOnDelete();
            $t->foreignId('instance_id')->constrained('tm_workflow_instances')->restrictOnDelete(); $t->timestamp('joined_at', 6); $t->timestamp('left_at', 6)->nullable();
            $t->unsignedInteger('enrollment_revision'); $t->unique(['group_id', 'instance_id', 'enrollment_revision'], 'tm_group_member_revision_unique'); $t->index(['group_id', 'id']);
        });
        Schema::create('tm_active_group_members', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('group_id'); $t->unsignedBigInteger('instance_id'); $t->foreignId('member_id')->unique()->constrained('tm_process_group_members')->restrictOnDelete();
            $t->foreign('group_id')->references('id')->on('tm_process_groups')->restrictOnDelete(); $t->foreign('instance_id')->references('id')->on('tm_workflow_instances')->restrictOnDelete();
            $t->primary(['group_id', 'instance_id']);
        });
        Schema::create('tm_milestone_intents', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('intent_key')->unique(); $t->string('scope_key', 120); $t->foreignId('instance_id')->constrained('tm_workflow_instances')->restrictOnDelete();
            $t->string('milestone_key', 100); $t->unsignedInteger('instance_version'); $t->string('operation_id', 120); $t->timestamp('created_at', 6);
            $t->string('transaction_ref', 100)->nullable(); $t->string('state', 30)->default('pending');
            $t->unique(['instance_id', 'instance_version', 'milestone_key'], 'tm_milestone_intent_version_unique'); $t->index(['scope_key', 'state', 'id']);
        });
        Schema::create('tm_process_milestones', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->string('scope_key', 120); $t->foreignId('instance_id')->constrained('tm_workflow_instances')->restrictOnDelete();
            $t->foreignId('intent_id')->unique()->constrained('tm_milestone_intents')->restrictOnDelete(); $t->string('milestone_key', 100);
            $t->timestamp('committed_at', 6); $t->timestamp('verified_at', 6); $t->index(['instance_id', 'milestone_key', 'committed_at'], 'tm_milestone_cutoff_index');
        });
        Schema::create('tm_group_operations', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('run_key')->unique(); $t->string('scope_key', 120); $t->foreignId('group_id')->constrained('tm_process_groups')->restrictOnDelete();
            $t->foreignId('policy_id')->constrained('tm_group_lifecycle_policies')->restrictOnDelete(); $t->unsignedInteger('source_revision'); $t->timestamp('cutoff_at', 6);
            $t->string('initiator_ref', 120); $t->string('operation_id', 120); $t->string('state', 30)->default('processing'); $t->unsignedInteger('version')->default(1); $t->timestamp('created_at', 6);
            $t->unique(['scope_key', 'operation_id']); $t->index(['scope_key', 'state', 'id']);
        });
        Schema::create('tm_group_operation_items', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('run_id')->constrained('tm_group_operations')->restrictOnDelete();
            $t->foreignId('member_id')->constrained('tm_process_group_members')->restrictOnDelete(); $t->foreignId('instance_id')->constrained('tm_workflow_instances')->restrictOnDelete();
            $t->unsignedInteger('expected_instance_version'); $t->string('state', 30)->default('pending'); $t->uuid('lease_token')->nullable(); $t->timestamp('lease_expires_at', 6)->nullable();
            $t->unsignedInteger('attempts')->default(0); $t->string('result', 30)->nullable(); $t->string('reason_code', 100)->nullable(); $t->string('error_code', 100)->nullable();
            $t->string('operation_id', 120)->nullable(); $t->timestamp('processed_at', 6)->nullable(); $t->unique(['run_id', 'member_id']);
            $t->index(['run_id', 'state', 'lease_expires_at', 'member_id'], 'tm_group_claim_index');
        });
    }
    public function down(): void
    {
        if (DB::table('tm_process_groups')->exists() || DB::table('tm_milestone_intents')->exists()) { throw new RuntimeException('group_rollback_requires_export'); }
        Schema::dropIfExists('tm_group_operation_items'); Schema::dropIfExists('tm_group_operations'); Schema::dropIfExists('tm_process_milestones'); Schema::dropIfExists('tm_milestone_intents');
        Schema::dropIfExists('tm_active_group_members'); Schema::dropIfExists('tm_process_group_members');
        Schema::table('tm_process_groups', fn (Blueprint $t) => $t->dropForeign(['policy_id']));
        Schema::dropIfExists('tm_group_lifecycle_policies'); Schema::dropIfExists('tm_process_groups');
    }
};
