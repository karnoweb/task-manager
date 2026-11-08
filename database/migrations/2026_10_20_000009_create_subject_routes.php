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
        Schema::create(Tables::name('subject_conversions'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->foreignId('origin_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('destination_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->string('route_ref', 100);
            $t->string('reason_code', 100);
            $t->string('operation_id', 120);
            $t->dateTime('created_at');
            $t->unique(['scope_key', 'operation_id']);
        });
        Schema::create(Tables::name('external_sagas'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->uuid('saga_key')->unique();
            $t->string('scope_key', 120);
            $t->foreignId('origin_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->unsignedInteger('origin_version');
            $t->string('route_ref', 100);
            $t->string('source_transition', 100);
            $t->string('reason_code', 100);
            $t->text('prepared_payload');
            $t->text('receipt')->nullable();
            $t->string('state', 30)->default('pending_external');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('attempts')->default(0);
            $t->string('failure_code', 100)->nullable();
            $t->dateTime('created_at');
            $t->dateTime('confirmed_at')->nullable();
            $t->index(['scope_key', 'state', 'id']);
        });
        Schema::create(Tables::name('external_case_subjects'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('case_id')->constrained(Tables::name('process_cases'))->restrictOnDelete();
            $t->foreignId('saga_id')->unique()->constrained(Tables::name('external_sagas'))->restrictOnDelete();
            $t->string('route_ref', 100);
            $t->string('subject_type', 100);
            $t->string('subject_id', 120);
            $t->string('external_process_key', 120);
        });
        Schema::create(Tables::name('active_external_sagas'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->unsignedBigInteger('origin_id')->primary();
            $t->foreign('origin_id')->references('id')->on(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->foreignId('saga_id')->unique()->constrained(Tables::name('external_sagas'))->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('subject_conversions'))->exists() || DB::table(Tables::name('external_sagas'))->exists()) {
            throw new RuntimeException('subject_route_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('active_external_sagas'));
        Schema::dropIfExists(Tables::name('external_case_subjects'));
        Schema::dropIfExists(Tables::name('external_sagas'));
        Schema::dropIfExists(Tables::name('subject_conversions'));
    }
};
