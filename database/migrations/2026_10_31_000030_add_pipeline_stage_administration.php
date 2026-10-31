<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('pipeline_stages'), function (Blueprint $table) {
            $table->string('label', 120)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
        });
        // Existing stages keep their historical (insertion) order.
        $positions = [];
        foreach (DB::table(Tables::name('pipeline_stages'))->orderBy('pipeline_id')->orderBy('id')->get(['id', 'pipeline_id']) as $stage) {
            $positions[$stage->pipeline_id] = ($positions[$stage->pipeline_id] ?? -1) + 1;
            DB::table(Tables::name('pipeline_stages'))->where('id', $stage->id)->update(['position' => $positions[$stage->pipeline_id]]);
        }
        Schema::table(Tables::name('pipeline_stages'), fn (Blueprint $table) => $table->index(['pipeline_id', 'position'], Tables::name('stage_position_idx')));
    }

    public function down(): void
    {
        if (DB::table(Tables::name('pipeline_stages'))->where('is_active', false)->orWhereNotNull('label')->exists()) {
            throw new RuntimeException('pipeline_stage_administration_downgrade_requires_migration');
        }
        Schema::table(Tables::name('pipeline_stages'), function (Blueprint $table) {
            $table->dropIndex(Tables::name('stage_position_idx'));
            $table->dropColumn(['label', 'position', 'is_active']);
        });
    }
};
