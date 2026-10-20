<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_stage_policies', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->string('scope_key', 120); $t->foreignId('stage_id')->constrained('tm_pipeline_stages')->restrictOnDelete();
            $t->unsignedInteger('revision'); $t->text('sla'); $t->boolean('dead_candidate')->default(false); $t->unique(['stage_id', 'revision']);
        });
        Schema::table('tm_pipeline_stages', function (Blueprint $t) { $t->unsignedBigInteger('policy_id')->nullable(); $t->foreign('policy_id')->references('id')->on('tm_stage_policies')->restrictOnDelete(); });
        Schema::table('tm_stage_visits', function (Blueprint $t) {
            $t->unsignedBigInteger('policy_id')->nullable(); $t->foreign('policy_id')->references('id')->on('tm_stage_policies')->restrictOnDelete();
            foreach (['deadline_at', 'warning_at', 'stale_at', 'last_activity_at', 'paused_at', 'warned_at', 'breached_at', 'stale_detected_at', 'dead_candidate_at', 'anchor_at'] as $column) { $t->timestamp($column)->nullable(); }
            $t->unsignedBigInteger('paused_seconds')->default(0); $t->unsignedBigInteger('elapsed_seconds')->default(0); $t->unsignedBigInteger('business_seconds')->default(0);
            $t->unsignedBigInteger('remaining_seconds')->nullable(); $t->unsignedBigInteger('budget_seconds')->nullable();
            $t->string('sla_state', 30)->default('healthy'); $t->unsignedInteger('sla_version')->default(1); $t->unsignedInteger('sla_generation')->default(1); $t->unsignedInteger('stale_generation')->default(1);
            $t->index(['scope_key', 'deadline_at'], 'tm_sla_due'); $t->index(['scope_key', 'warning_at'], 'tm_sla_warning'); $t->index(['scope_key', 'stale_at'], 'tm_sla_stale');
        });
        Schema::create('tm_sla_pauses', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('visit_id')->constrained('tm_stage_visits')->restrictOnDelete();
            $t->timestamp('started_at'); $t->timestamp('ended_at')->nullable(); $t->string('reason_code', 100); $t->string('actor_ref', 120);
        });
        Schema::create('tm_sla_events', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->string('scope_key', 120); $t->foreignId('visit_id')->constrained('tm_stage_visits')->restrictOnDelete();
            $t->foreignId('policy_id')->constrained('tm_stage_policies')->restrictOnDelete(); $t->string('event_key', 30); $t->timestamp('threshold_at'); $t->timestamp('detected_at');
            $t->unsignedInteger('generation')->default(1);
            $t->string('action_state', 30)->default('marked'); $t->string('reason', 100)->nullable();
            $t->unique(['visit_id', 'policy_id', 'generation', 'event_key'], 'tm_sla_event_dedupe');
        });
    }
    public function down(): void
    {
        if (DB::table('tm_stage_policies')->exists() || DB::table('tm_sla_events')->exists() || DB::table('tm_sla_pauses')->exists() || DB::table('tm_stage_visits')->whereNotNull('policy_id')->exists()) { throw new RuntimeException('sla_rollback_requires_export'); }
        Schema::dropIfExists('tm_sla_events'); Schema::dropIfExists('tm_sla_pauses');
        Schema::table('tm_stage_visits', function (Blueprint $t) {
            $t->dropForeign(['policy_id']); $t->dropIndex('tm_sla_due'); $t->dropIndex('tm_sla_warning'); $t->dropIndex('tm_sla_stale');
            $t->dropColumn(['policy_id', 'deadline_at', 'warning_at', 'stale_at', 'last_activity_at', 'paused_at', 'warned_at', 'breached_at', 'stale_detected_at', 'dead_candidate_at', 'anchor_at', 'paused_seconds', 'elapsed_seconds', 'business_seconds', 'remaining_seconds', 'budget_seconds', 'sla_state', 'sla_version', 'sla_generation', 'stale_generation']);
        });
        Schema::table('tm_pipeline_stages', function (Blueprint $t) { $t->dropForeign(['policy_id']); $t->dropColumn('policy_id'); });
        Schema::dropIfExists('tm_stage_policies');
    }
};
