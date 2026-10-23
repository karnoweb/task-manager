<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tm_reminders', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->string('created_by', 120)->nullable();
            $table->text('body')->nullable();
            $table->string('timezone', 100)->default('UTC');
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('deleted_at', 6)->nullable();
            $table->index(['scope_key', 'subject_type', 'subject_id'], 'tm_reminder_subject');
        });
        DB::table('tm_reminders')->update(['created_by' => DB::raw('actor_ref')]);
    }

    public function down(): void
    {
        if (DB::table('tm_reminders')->whereNull('task_id')->exists()) {
            throw new RuntimeException('general_reminder_downgrade_requires_migration');
        }
        Schema::table('tm_reminders', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->dropIndex('tm_reminder_subject');
            $table->dropColumn(['created_by', 'body', 'timezone', 'subject_type', 'subject_id', 'created_at', 'updated_at', 'completed_at', 'deleted_at']);
        });
    }
};
