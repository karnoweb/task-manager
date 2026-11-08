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
        foreach ([Tables::name('comments'), Tables::name('attachments')] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
                $t->bigIncrements('id');
                $t->uuid('public_key')->unique();
                $t->string('scope_key', 120);
                $t->foreignId('task_id')->constrained(Tables::name('tasks'))->restrictOnDelete();
                $t->string('author_ref', 120);
                if ($table === Tables::name('comments')) {
                    $t->text('body');
                } else {
                    $t->string('file_ref', 255);
                    $t->string('media_type', 100);
                    $t->unsignedBigInteger('size');
                }
                $t->unsignedInteger('version')->default(1);
                $t->dateTime('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::table(Tables::name('comments'))->exists() || DB::table(Tables::name('attachments'))->exists()) {
            throw new RuntimeException('resource_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('attachments'));
        Schema::dropIfExists(Tables::name('comments'));
    }
};
