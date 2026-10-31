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
        Schema::create(Tables::name('entry_routes'), function (Blueprint $table) {
            $table->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $table->string('scope_key', 120);
            $table->string('routing_key', 120);
            $table->foreignId('pipeline_id')->nullable()->constrained(Tables::name('pipelines'))->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->primary(['scope_key', 'routing_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('entry_routes'));
    }
};
