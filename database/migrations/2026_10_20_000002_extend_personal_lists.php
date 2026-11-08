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
        Schema::table(Tables::name('lists'), function (Blueprint $t) {
            $t->boolean('is_inbox')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->decimal('rank', 20, 8)->default(0);
            $t->dateTime('archived_at')->nullable();
        });
        DB::table(Tables::name('lists'))->where('name', 'Inbox')->update(['is_inbox' => true]);
    }

    public function down(): void
    {
        Schema::table(Tables::name('lists'), fn (Blueprint $t) => $t->dropColumn(['is_inbox', 'version', 'rank', 'archived_at']));
    }
};
