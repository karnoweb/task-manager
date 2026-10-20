<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tm_tasks', function (Blueprint $t) {
            $t->timestamp('starts_at', 6)->nullable(); $t->timestamp('ends_at', 6)->nullable(); $t->timestamp('due_at', 6)->nullable();
            $t->string('timezone', 100)->default('UTC'); $t->boolean('all_day')->default(false); $t->date('local_start_date')->nullable(); $t->date('local_end_date')->nullable(); $t->unsignedInteger('date_revision')->default(1);
            $t->index(['scope_key', 'due_at', 'id'], 'tm_task_due_query'); $t->index(['scope_key', 'starts_at', 'ends_at'], 'tm_task_range_query');
        });
        DB::table('tm_tasks')->whereNotNull('planned_start_at')->whereNotNull('planned_finish_at')->whereColumn('planned_finish_at','>','planned_start_at')->orderBy('id')->chunkById(500,function($rows){foreach($rows as $row){DB::table('tm_tasks')->where('id',$row->id)->update(['starts_at'=>$row->planned_start_at,'ends_at'=>$row->planned_finish_at]);}});
        Schema::create('tm_recurrences', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('series_key')->unique(); $t->string('scope_key', 120); $t->string('owner_ref', 120); $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1); $t->string('state', 20)->default('active'); $t->string('calendar_system', 20); $t->string('timezone', 100); $t->string('local_anchor', 19); $t->text('rule'); $t->text('template');
            $t->unique('task_id'); $t->index(['scope_key', 'owner_ref', 'state', 'id'], 'tm_series_worker');
        });
        Schema::create('tm_recurrence_occurrences', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('recurrence_id')->constrained('tm_recurrences')->restrictOnDelete(); $t->string('occurrence_key', 40); $t->unsignedInteger('rule_revision'); $t->string('state', 20)->default('active');
            $t->foreignId('task_id')->unique()->constrained('tm_tasks')->restrictOnDelete(); $t->timestamp('scheduled_at', 6); $t->timestamp('created_at', 6);
            $t->unique(['recurrence_id', 'occurrence_key'], 'tm_occurrence_dedupe'); $t->index(['recurrence_id', 'scheduled_at']);
        });
        Schema::create('tm_recurrence_exceptions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('recurrence_id')->constrained('tm_recurrences')->restrictOnDelete(); $t->string('occurrence_key', 40); $t->unsignedInteger('version')->default(1); $t->boolean('cancelled')->default(false); $t->timestamp('override_at', 6)->nullable();
            $t->unique(['recurrence_id', 'occurrence_key'], 'tm_exception_identity');
        });
        Schema::create('tm_reminders', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('reminder_key')->unique(); $t->string('scope_key', 120); $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete(); $t->string('actor_ref', 120);
            $t->string('anchor', 20); $t->integer('offset_seconds'); $t->timestamp('absolute_at', 6)->nullable(); $t->timestamp('scheduled_at', 6)->nullable(); $t->unsignedInteger('source_revision');
            $t->string('state', 20)->default('pending'); $t->unsignedInteger('version')->default(1); $t->unsignedInteger('generation')->default(1); $t->string('channel', 30)->default('in_app');
            $t->index(['scope_key', 'actor_ref', 'state', 'scheduled_at', 'id'], 'tm_reminder_due');
        });
        Schema::create('tm_reminder_deliveries', function (Blueprint $t) {
            $t->foreignId('reminder_id')->constrained('tm_reminders')->restrictOnDelete(); $t->unsignedInteger('generation'); $t->uuid('event_id'); $t->foreign('event_id')->references('event_id')->on('tm_outbox')->restrictOnDelete(); $t->timestamp('created_at', 6); $t->primary(['reminder_id', 'generation']);
        });
    }
    public function down(): void
    {
        foreach (['tm_reminder_deliveries', 'tm_reminders', 'tm_recurrence_exceptions', 'tm_recurrence_occurrences', 'tm_recurrences'] as $table) { Schema::dropIfExists($table); }
        Schema::table('tm_tasks', function (Blueprint $t) { $t->dropIndex('tm_task_due_query'); $t->dropIndex('tm_task_range_query'); $t->dropColumn(['starts_at','ends_at','due_at','timezone','all_day','local_start_date','local_end_date','date_revision']); });
    }
};
