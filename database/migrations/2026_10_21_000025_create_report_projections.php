<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up():void{Schema::create('tm_report_projections',function(Blueprint $t){$t->collation=IdentifierCollation::forConnection(Schema::getConnection());$t->bigIncrements('id');$t->string('scope_key',120);$t->string('actor_ref',120);$t->string('report_key',64);$t->string('permission_hash',64);$t->string('source_hash',64);$t->text('definition');$t->text('metrics');$t->timestamp('captured_at',6);$t->unsignedInteger('version')->default(1);$t->unique(['scope_key','actor_ref','report_key'],'tm_report_projection_identity');});Schema::create('tm_report_projection_items',function(Blueprint $t){$t->foreignId('projection_id')->constrained('tm_report_projections')->restrictOnDelete();$t->foreignId('instance_id')->constrained('tm_workflow_instances')->restrictOnDelete();$t->primary(['projection_id','instance_id']);});}
    public function down():void{Schema::dropIfExists('tm_report_projection_items');Schema::dropIfExists('tm_report_projections');}
};
