<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['tm_comments', 'tm_attachments'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
                $t->bigIncrements('id'); $t->uuid('public_key')->unique(); $t->string('scope_key', 120);
                $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete(); $t->string('author_ref', 120);
                if ($table === 'tm_comments') { $t->text('body'); }
                else { $t->string('file_ref', 255); $t->string('media_type', 100); $t->unsignedBigInteger('size'); }
                $t->unsignedInteger('version')->default(1); $t->timestamp('deleted_at')->nullable();
            });
        }
    }
    public function down(): void
    {
        if (DB::table('tm_comments')->exists() || DB::table('tm_attachments')->exists()) { throw new RuntimeException('resource_rollback_requires_export'); }
        Schema::dropIfExists('tm_attachments'); Schema::dropIfExists('tm_comments');
    }
};
