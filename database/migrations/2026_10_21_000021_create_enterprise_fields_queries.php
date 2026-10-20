<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_custom_fields', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection()); $t->bigIncrements('id'); $t->uuid('field_key')->unique(); $t->string('scope_key', 120); $t->string('name'); $t->string('type', 30); $t->text('options'); $t->string('read_capability', 120)->nullable(); $t->boolean('archived')->default(false); $t->unsignedInteger('version')->default(1); $t->index(['scope_key','archived','id']);
        });
        Schema::create('tm_custom_values', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection()); $t->bigIncrements('id'); $t->foreignId('field_id')->constrained('tm_custom_fields')->restrictOnDelete(); $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->text('text_value')->nullable(); $t->bigInteger('number_scaled')->nullable(); $t->boolean('bool_value')->nullable(); $t->date('date_value')->nullable(); $t->timestamp('datetime_value',6)->nullable(); $t->string('key_value',255)->nullable(); $t->unsignedInteger('version')->default(1);
            $t->unique(['field_id','task_id']); foreach (['number_scaled','date_value','datetime_value','key_value','bool_value'] as $column) { $t->index(['field_id',$column,'task_id'],'tm_cf_'.$column); }
        });
        Schema::create('tm_custom_selections', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection()); $t->foreignId('field_id')->constrained('tm_custom_fields')->restrictOnDelete(); $t->foreignId('task_id')->constrained('tm_tasks')->restrictOnDelete(); $t->string('option_key',100); $t->primary(['field_id','task_id','option_key']); $t->index(['field_id','option_key','task_id'],'tm_custom_option_query');
        });
        Schema::create('tm_task_presets', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection()); $t->bigIncrements('id'); $t->uuid('preset_key')->unique(); $t->string('scope_key',120); $t->string('owner_ref',120); $t->string('name'); $t->text('definition'); $t->unsignedInteger('version')->default(1); $t->index(['scope_key','owner_ref','id']);
        });
        Schema::create('tm_visibility_epochs', function (Blueprint $t) { $t->collation = IdentifierCollation::forConnection(Schema::getConnection()); $t->string('scope_key',120)->primary(); $t->unsignedBigInteger('version')->default(1); });
        Schema::create('tm_query_cursors', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection()); $t->string('token_hash',64)->primary(); $t->string('scope_key',120); $t->string('actor_ref',120); $t->string('query_hash',64); $t->string('permission_hash',64); $t->text('position'); $t->timestamp('expires_at',6); $t->index(['scope_key','actor_ref','expires_at'],'tm_query_cursor_expiry');
        });
        Schema::table('tm_tasks', function (Blueprint $t) { $t->unsignedSmallInteger('search_revision')->default(1); });
        DB::table('tm_tasks')->orderBy('id')->chunkById(500,function($rows){foreach($rows as $row){DB::table('tm_tasks')->where('id',$row->id)->update(['title_search_normalized'=>\Karnoweb\TaskManager\Enterprise\TextNormalizer::normalize($row->title),'search_revision'=>2]);}});
    }
    public function down(): void
    {
        foreach (['tm_query_cursors','tm_visibility_epochs','tm_task_presets','tm_custom_selections','tm_custom_values','tm_custom_fields'] as $table) { Schema::dropIfExists($table); } Schema::table('tm_tasks', fn (Blueprint $t) => $t->dropColumn('search_revision'));
    }
};
