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
        Schema::table(Tables::name('handoffs'), fn (Blueprint $t) => $t->unsignedInteger('repair_origin_version')->nullable());
        Schema::create(Tables::name('handoff_repairs'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id');
            $t->foreignId('handoff_id')->constrained(Tables::name('handoffs'))->restrictOnDelete();
            $t->string('action', 30);
            $t->unsignedInteger('accepted_origin_version');
            $t->string('actor_ref', 120);
            $t->string('reason_code', 100);
            $t->string('operation_id', 120);
            $t->timestamp('occurred_at');
            $t->unique(['handoff_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table(Tables::name('handoff_repairs'))->exists()) {
            throw new RuntimeException('handoff_repair_rollback_requires_export');
        }
        Schema::dropIfExists(Tables::name('handoff_repairs'));
        Schema::table(Tables::name('handoffs'), fn (Blueprint $t) => $t->dropColumn('repair_origin_version'));
    }
};
