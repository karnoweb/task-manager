<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('outbox'), function (Blueprint $t) {
            $t->unsignedInteger('schema_version')->default(1);
        });
        Schema::create(Tables::name('event_lineage'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->char('event_id', 36)->primary();
            $t->char('root_event_id', 36);
            $t->unsignedSmallInteger('depth');
            $t->index('root_event_id');
        });
        IdentifierCollation::align(Schema::getConnection(), Tables::name('event_lineage'), 'event_id');
        IdentifierCollation::align(Schema::getConnection(), Tables::name('event_lineage'), 'root_event_id');
        Schema::table(Tables::name('event_lineage'), function (Blueprint $t) {
            $t->foreign('event_id')->references('event_id')->on(Tables::name('outbox'))->restrictOnDelete();
            $t->foreign('root_event_id')->references('event_id')->on(Tables::name('outbox'))->restrictOnDelete();
        });
        Schema::create(Tables::name('automation_rules'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('rule_key')->unique();
            $t->string('scope_key', 120);
            $t->string('owner_ref', 120);
            $t->string('trigger', 100);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->index(['scope_key', 'owner_ref', 'trigger', 'active'], Tables::name('automation_trigger'));
        });
        Schema::create(Tables::name('automation_rule_revisions'), function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('rule_id')->constrained(Tables::name('automation_rules'))->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->text('definition');
            $t->unique(['rule_id', 'revision']);
        });
        Schema::create(Tables::name('automation_runs'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('rule_id')->constrained(Tables::name('automation_rules'))->restrictOnDelete();
            $t->char('event_id', 36);
            $t->unsignedInteger('rule_revision');
            $t->string('action_key', 100);
            $t->string('state', 30);
            $t->string('reason_code', 80)->nullable();
            $t->timestamp('occurred_at', 6);
            $t->unique(['rule_id', 'event_id', 'action_key'], Tables::name('automation_dedupe'));
        });
        IdentifierCollation::align(Schema::getConnection(), Tables::name('automation_runs'), 'event_id');
        Schema::table(Tables::name('automation_runs'), function (Blueprint $t) {
            $t->foreign('event_id')->references('event_id')->on(Tables::name('outbox'))->restrictOnDelete();
        });
        Schema::create(Tables::name('automation_budgets'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->char('root_event_id', 36);
            $t->unsignedInteger('used')->default(0);
            $t->primary(['scope_key', 'root_event_id']);
        });
        IdentifierCollation::align(Schema::getConnection(), Tables::name('automation_budgets'), 'root_event_id');
        Schema::table(Tables::name('automation_budgets'), function (Blueprint $t) {
            $t->foreign('root_event_id')->references('event_id')->on(Tables::name('outbox'))->restrictOnDelete();
        });
        Schema::create(Tables::name('automation_throttles'), function (Blueprint $t) {
            $t->foreignId('rule_id')->constrained(Tables::name('automation_rules'))->restrictOnDelete();
            $t->string('hour_key', 13);
            $t->unsignedInteger('used')->default(0);
            $t->primary(['rule_id', 'hour_key']);
        });
        Schema::create(Tables::name('task_label_keys'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
            $t->string('label_key', 100);
            $t->primary(['task_id', 'label_key']);
        });
        Schema::create(Tables::name('event_subscriptions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('subscription_key')->unique();
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->string('event_type', 100);
            $t->string('consumer_key', 100);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['scope_key', 'actor_ref', 'event_type', 'consumer_key'], Tables::name('subscription_identity'));
        });
        Schema::create(Tables::name('event_deliveries'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('subscription_id')->constrained(Tables::name('event_subscriptions'))->restrictOnDelete();
            $t->char('event_id', 36);
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at', 6)->nullable();
            $t->timestamp('lease_until', 6)->nullable();
            $t->uuid('lease_token')->nullable();
            $t->string('reason_code', 80)->nullable();
            $t->unique(['subscription_id', 'event_id'], Tables::name('subscription_delivery'));
            $t->index(['state', 'next_attempt_at', 'id'], Tables::name('event_delivery_ready'));
        });
        IdentifierCollation::align(Schema::getConnection(), Tables::name('event_deliveries'), 'event_id');
        Schema::table(Tables::name('event_deliveries'), function (Blueprint $t) {
            $t->foreign('event_id')->references('event_id')->on(Tables::name('outbox'))->restrictOnDelete();
        });
        Schema::create(Tables::name('event_offsets'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->unsignedBigInteger('last_id')->default(0);
            $t->primary(['scope_key', 'actor_ref']);
        });
    }

    public function down(): void
    {
        foreach ([Tables::name('event_offsets'), Tables::name('event_deliveries'), Tables::name('event_subscriptions'), Tables::name('task_label_keys'), Tables::name('automation_throttles'), Tables::name('automation_budgets'), Tables::name('automation_runs'), Tables::name('automation_rule_revisions'), Tables::name('automation_rules'), Tables::name('event_lineage')] as $table) {
            Schema::dropIfExists($table);
        }Schema::table(Tables::name('outbox'), fn (Blueprint $t) => $t->dropColumn('schema_version'));
    }
};
