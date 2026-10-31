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
        Schema::create(Tables::name('stage_policies'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->foreignId('stage_id')->constrained(Tables::name('pipeline_stages'))->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->text('sla');
            $t->boolean('dead_candidate')->default(false);
            $t->unique(['stage_id', 'revision']);
        });
        Schema::table(Tables::name('pipeline_stages'), function (Blueprint $t) {
            $t->unsignedBigInteger('policy_id')->nullable();
            $t->foreign('policy_id')->references('id')->on(Tables::name('stage_policies'))->restrictOnDelete();
        });
        Schema::table(Tables::name('stage_visits'), function (Blueprint $t) {
            $t->unsignedBigInteger('policy_id')->nullable();
            $t->foreign('policy_id')->references('id')->on(Tables::name('stage_policies'))->restrictOnDelete();
            foreach (['deadline_at', 'warning_at', 'stale_at', 'last_activity_at', 'paused_at', 'warned_at', 'breached_at', 'stale_detected_at', 'dead_candidate_at', 'anchor_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->unsignedBigInteger('paused_seconds')->default(0);
            $t->unsignedBigInteger('elapsed_seconds')->default(0);
            $t->unsignedBigInteger('business_seconds')->default(0);
            $t->unsignedBigInteger('remaining_seconds')->nullable();
            $t->unsignedBigInteger('budget_seconds')->nullable();
            $t->string('sla_state', 30)->default('healthy');
            $t->unsignedInteger('sla_version')->default(1);
            $t->unsignedInteger('sla_generation')->default(1);
            $t->unsignedInteger('stale_generation')->default(1);
            $t->index(['scope_key', 'deadline_at'], Tables::name('sla_due'));
            $t->index(['scope_key', 'warning_at'], Tables::name('sla_warning'));
            $t->index(['scope_key', 'stale_at'], Tables::name('sla_stale'));
        });
        Schema::create(Tables::name('sla_pauses'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('visit_id')->constrained(Tables::name('stage_visits'))->restrictOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('reason_code', 100);
            $t->string('actor_ref', 120);
        });
        Schema::create(Tables::name('sla_events'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->foreignId('visit_id')->constrained(Tables::name('stage_visits'))->restrictOnDelete();
            $t->foreignId('policy_id')->constrained(Tables::name('stage_policies'))->restrictOnDelete();
            $t->string('event_key', 30);
            $t->timestamp('threshold_at');
            $t->timestamp('detected_at');
            $t->unsignedInteger('generation')->default(1);
            $t->string('action_state', 30)->default('marked');
            $t->string('reason', 100)->nullable();
            $t->unique(['visit_id', 'policy_id', 'generation', 'event_key'], Tables::name('sla_event_dedupe'));
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('stage_policies'))->exists() || DB::table(Tables::name('sla_events'))->exists() || DB::table(Tables::name('sla_pauses'))->exists() || DB::table(Tables::name('stage_visits'))->whereNotNull('policy_id')->exists()) {
            throw new RuntimeException('sla_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('sla_events'));
        Schema::dropIfExists(Tables::name('sla_pauses'));
        Schema::table(Tables::name('stage_visits'), function (Blueprint $t) {
            $t->dropForeign(['policy_id']);
            $t->dropIndex(Tables::name('sla_due'));
            $t->dropIndex(Tables::name('sla_warning'));
            $t->dropIndex(Tables::name('sla_stale'));
            $t->dropColumn(['policy_id', 'deadline_at', 'warning_at', 'stale_at', 'last_activity_at', 'paused_at', 'warned_at', 'breached_at', 'stale_detected_at', 'dead_candidate_at', 'anchor_at', 'paused_seconds', 'elapsed_seconds', 'business_seconds', 'remaining_seconds', 'budget_seconds', 'sla_state', 'sla_version', 'sla_generation', 'stale_generation']);
        });
        Schema::table(Tables::name('pipeline_stages'), function (Blueprint $t) {
            $t->dropForeign(['policy_id']);
            $t->dropColumn('policy_id');
        });
        Schema::dropIfExists(Tables::name('stage_policies'));
    }
};
