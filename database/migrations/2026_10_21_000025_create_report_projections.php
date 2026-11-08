<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('report_projections'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->string('scope_key', 120);
            $t->string('actor_ref', 120);
            $t->string('report_key', 64);
            $t->string('permission_hash', 64);
            $t->string('source_hash', 64);
            $t->text('definition');
            $t->text('metrics');
            $t->dateTime('captured_at', 6);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['scope_key', 'actor_ref', 'report_key'], Tables::name('report_projection_identity'));
        });
        Schema::create(Tables::name('report_projection_items'), function (Blueprint $t) {
            $t->foreignId('projection_id')->constrained(Tables::name('report_projections'))->restrictOnDelete();
            $t->foreignId('instance_id')->constrained(Tables::name('workflow_instances'))->restrictOnDelete();
            $t->primary(['projection_id', 'instance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('report_projection_items'));
        Schema::dropIfExists(Tables::name('report_projections'));
    }
};
