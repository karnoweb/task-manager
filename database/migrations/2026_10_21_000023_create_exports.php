<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up():void
    {
        Schema::create('tm_exports',function(Blueprint $t){$t->collation=IdentifierCollation::forConnection(Schema::getConnection());$t->bigIncrements('id');$t->uuid('export_key')->unique();$t->string('scope_key',120);$t->string('actor_ref',120);$t->text('definition');$t->string('permission_hash',64);$t->string('state',20)->default('pending');$t->unsignedInteger('version')->default(1);$t->unsignedInteger('attempts')->default(0);$t->unsignedBigInteger('cutoff_id');$t->string('file_ref',100)->nullable();$t->timestamp('expires_at',6);$t->uuid('lease_token')->nullable();$t->timestamp('lease_until',6)->nullable();$t->string('reason_code',80)->nullable();$t->index(['scope_key','actor_ref','state','id'],'tm_export_worker');});
        Schema::create('tm_export_items',function(Blueprint $t){$t->foreignId('export_id')->constrained('tm_exports')->restrictOnDelete();$t->string('resource_type',20);$t->bigIncrements('id');$t->foreignId('task_id')->nullable()->constrained('tm_tasks')->restrictOnDelete();$t->foreignId('instance_id')->nullable()->constrained('tm_workflow_instances')->restrictOnDelete();$t->unique(['export_id','task_id']);$t->unique(['export_id','instance_id']);});
    }
    public function down():void{Schema::dropIfExists('tm_export_items');Schema::dropIfExists('tm_exports');}
};
