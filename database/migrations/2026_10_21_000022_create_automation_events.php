<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tm_outbox', function (Blueprint $t) {
            $t->unsignedInteger('schema_version')->default(1);
        });
        Schema::create('tm_event_lineage', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->uuid('event_id')->primary();
            $t->uuid('root_event_id');
            $t->unsignedSmallInteger('depth');
            $t->index('root_event_id');
            $t->foreign('event_id')->references('event_id')->on('tm_outbox')->restrictOnDelete();
            $t->foreign('root_event_id')->references('event_id')->on('tm_outbox')->restrictOnDelete();
        });
        Schema::create('tm_automation_rules', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('rule_key')->unique();
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->string('trigger', 100);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->index(['scope_key', 'owner_ref', 'trigger', 'active'], 'tm_automation_trigger');
        });
        Schema::create('tm_automation_rule_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('rule_id')->constrained('tm_automation_rules')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->text('definition');
            $t->unique(['rule_id', 'revision']);
        });
        Schema::create('tm_automation_runs', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('rule_id')->constrained('tm_automation_rules')->restrictOnDelete();
            $t->uuid('event_id');
            $t->foreign('event_id')->references('event_id')->on('tm_outbox')->restrictOnDelete();
            $t->unsignedInteger('rule_revision');
            $t->string('action_key', 100);
            $t->string('state', 30);
            $t->string('reason_code', 80)->nullable();
            $t->timestamp('occurred_at', 6);
            $t->unique(['rule_id', 'event_id', 'action_key'], 'tm_automation_dedupe');
        });
        Schema::create('tm_automation_budgets', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->uuid('root_event_id');
            $t->foreign('root_event_id')->references('event_id')->on('tm_outbox')->restrictOnDelete();
            $t->unsignedInteger('used')->default(0);
            $t->primary(['scope_key', 'root_event_id']);
        });
        Schema::create('tm_automation_throttles', function (Blueprint $t) {
            $t->foreignId('rule_id')->constrained('tm_automation_rules')->restrictOnDelete();
            $t->string('hour_key', 13);
            $t->unsignedInteger('used')->default(0);
            $t->primary(['rule_id', 'hour_key']);
        });
        Schema::create('tm_task_label_keys', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('label_key', 100);
            $t->primary(['task_id', 'label_key']);
        });
        Schema::create('tm_event_subscriptions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('subscription_key')->unique();
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->string('event_type', 100);
            $t->string('consumer_key', 100);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['scope_key', 'actor_ref', 'event_type', 'consumer_key'], 'tm_subscription_identity');
        });
        Schema::create('tm_event_deliveries', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('subscription_id')->constrained('tm_event_subscriptions')->restrictOnDelete();
            $t->uuid('event_id');
            $t->foreign('event_id')->references('event_id')->on('tm_outbox')->restrictOnDelete();
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at', 6)->nullable();
            $t->timestamp('lease_until', 6)->nullable();
            $t->uuid('lease_token')->nullable();
            $t->string('reason_code', 80)->nullable();
            $t->unique(['subscription_id', 'event_id'], 'tm_subscription_delivery');
            $t->index(['state', 'next_attempt_at', 'id'], 'tm_event_delivery_ready');
        });
        Schema::create('tm_event_offsets', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->unsignedBigInteger('last_id')->default(0);
            $t->primary(['scope_key', 'actor_ref']);
        });
    }

    public function down(): void
    {
        foreach (['tm_event_offsets', 'tm_event_deliveries', 'tm_event_subscriptions', 'tm_task_label_keys', 'tm_automation_throttles', 'tm_automation_budgets', 'tm_automation_runs', 'tm_automation_rule_revisions', 'tm_automation_rules', 'tm_event_lineage'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('tm_outbox',fn (Blueprint $t) => $t->dropColumn('schema_version'));
    }
};
