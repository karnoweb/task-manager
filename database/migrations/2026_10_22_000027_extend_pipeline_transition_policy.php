<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('pipelines'), function (Blueprint $table) {
            $table->string('transition_mode', 20)->default('custom');
            $table->dateTime('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table(Tables::name('pipelines'))->where('transition_mode', '!=', 'custom')->orWhereNotNull('retired_at')->exists()) {
            throw new RuntimeException('pipeline_policy_downgrade_requires_migration');
        }
        Schema::table(Tables::name('pipelines'), fn (Blueprint $table) => $table->dropColumn(['transition_mode', 'retired_at']));
    }
};
