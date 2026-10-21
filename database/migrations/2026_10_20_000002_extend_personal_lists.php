<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tm_lists', function (Blueprint $t) {
            $t->boolean('is_inbox')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->decimal('rank', 20, 8)->default(0);
            $t->timestamp('archived_at')->nullable();
        });
        DB::table('tm_lists')->where('name', 'Inbox')->update(['is_inbox' => true]);
    }

    public function down(): void
    {
        Schema::table('tm_lists', fn (Blueprint $t) => $t->dropColumn(['is_inbox', 'version', 'rank', 'archived_at']));
    }
};
