<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('reminders'), function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->string('created_by', 120)->nullable();
            $table->text('body')->nullable();
            $table->string('timezone', 100)->default('UTC');
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['scope_key', 'subject_type', 'subject_id'], Tables::name('reminder_subject'));
        });
        DB::table(Tables::name('reminders'))->update(['created_by' => DB::raw('actor_ref')]);
    }

    public function down(): void
    {
        if (DB::table(Tables::name('reminders'))->whereNull('task_id')->exists()) {
            throw new RuntimeException('general_reminder_downgrade_requires_migration');
        }
        Schema::table(Tables::name('reminders'), function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->dropIndex(Tables::name('reminder_subject'));
            $table->dropColumn(['created_by', 'body', 'timezone', 'subject_type', 'subject_id', 'created_at', 'updated_at', 'completed_at', 'deleted_at']);
        });
    }
};
