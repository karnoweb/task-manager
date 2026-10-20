<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_project_templates', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->uuid('template_key')->unique(); $t->string('scope_key', 120); $t->string('owner_ref', 120); $t->string('name'); $t->unsignedInteger('version')->default(1);
            $t->index(['scope_key', 'owner_ref', 'id']);
        });
        Schema::create('tm_project_template_revisions', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('template_id')->constrained('tm_project_templates')->restrictOnDelete(); $t->unsignedInteger('revision'); $t->text('definition'); $t->string('definition_hash', 64); $t->timestamp('created_at', 6);
            $t->unique(['template_id', 'revision']);
        });
        Schema::create('tm_project_template_instances', function (Blueprint $t) {
            $t->foreignId('project_id')->primary()->constrained('tm_projects')->restrictOnDelete();
            $t->foreignId('template_revision_id')->constrained('tm_project_template_revisions')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tm_project_template_instances'); Schema::dropIfExists('tm_project_template_revisions'); Schema::dropIfExists('tm_project_templates');
    }
};
