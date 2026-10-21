<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tm_host_cutovers', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('source_key', 80);
            $t->string('owner_ref', 120);
            $t->string('mode', 20)->default('shadow');
            $t->unsignedInteger('version')->default(1);
            $t->text('mapping');
            $t->string('reconciled_hash', 64)->nullable();
            $t->timestamp('cutover_at')->nullable();
            $t->timestamp('rollback_at')->nullable();
            $t->unsignedBigInteger('cutover_event_id')->nullable();
            $t->unique(['scope_key', 'source_key']);
        });
        Schema::create('tm_host_imports', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('cutover_id')->constrained('tm_host_cutovers')->restrictOnDelete();
            $t->string('legacy_type', 80);
            $t->string('legacy_id', 120);
            $t->string('source_hash', 64);
            $t->text('source_snapshot');
            $t->unsignedBigInteger('task_id')->nullable();
            $t->foreign('task_id')->references('id')->on('tm_tasks')->restrictOnDelete();
            $t->unsignedBigInteger('instance_id')->nullable();
            $t->foreign('instance_id')->references('id')->on('tm_workflow_instances')->restrictOnDelete();
            $t->unsignedInteger('target_version');
            $t->string('target_hash', 64);
            $t->timestamp('imported_at');
            $t->unique(['cutover_id', 'legacy_type', 'legacy_id']);
            $t->unique('task_id');
            $t->unique('instance_id');
        });
        Schema::create('tm_host_series', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('cutover_id')->constrained('tm_host_cutovers')->restrictOnDelete();
            $t->unsignedBigInteger('legacy_series_id');
            $t->foreignId('recurrence_id')->constrained('tm_recurrences')->restrictOnDelete();
            $t->unique(['cutover_id', 'legacy_series_id']);
            $t->unique('recurrence_id');
        });
        Schema::create('tm_host_task_links', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->string('subject_type', 80);
            $t->string('subject_id', 120);
            $t->unique(['task_id', 'subject_type', 'subject_id'], 'tm_host_task_subject_unique');
            $t->index(['scope_key', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tm_host_task_links');
        Schema::dropIfExists('tm_host_series');
        Schema::dropIfExists('tm_host_imports');
        Schema::dropIfExists('tm_host_cutovers');
    }
};
