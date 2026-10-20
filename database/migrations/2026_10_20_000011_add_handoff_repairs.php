<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tm_handoffs', fn (Blueprint $t) => $t->unsignedInteger('repair_origin_version')->nullable());
        Schema::create('tm_handoff_repairs', function (Blueprint $t) {
            $t->collation = \Karnoweb\TaskManager\Schema\IdentifierCollation::forConnection(Schema::getConnection());
            $t->bigIncrements('id'); $t->foreignId('handoff_id')->constrained('tm_handoffs')->restrictOnDelete(); $t->string('action', 30);
            $t->unsignedInteger('accepted_origin_version'); $t->string('actor_ref', 120); $t->string('reason_code', 100); $t->string('operation_id', 120); $t->timestamp('occurred_at');
            $t->unique(['handoff_id', 'operation_id']);
        });
    }
    public function down(): void
    {
        if (DB::table('tm_handoff_repairs')->exists()) { throw new RuntimeException('handoff_repair_rollback_requires_export'); }
        Schema::dropIfExists('tm_handoff_repairs'); Schema::table('tm_handoffs', fn (Blueprint $t) => $t->dropColumn('repair_origin_version'));
    }
};
