<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_task_graph_locks', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120)->primary(); $t->unsignedBigInteger('version')->default(1);
        });
        Schema::create('tm_task_closure', function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->string('scope_key', 120); $t->foreignId('ancestor_task_id')->constrained('tm_tasks')->restrictOnDelete();
            $t->foreignId('descendant_task_id')->constrained('tm_tasks')->restrictOnDelete(); $t->unsignedTinyInteger('depth');
            $t->primary(['ancestor_task_id', 'descendant_task_id']); $t->index(['scope_key', 'descendant_task_id', 'depth'], 'tm_task_ancestor_lookup');
        });
        Schema::table('tm_projects', fn (Blueprint $t) => $t->unsignedTinyInteger('hierarchy_max_depth')->default(3));
        // Existing adjacency pointers remain the source of truth; derive, never invent, paths.
        DB::table('tm_tasks')->orderBy('id')->chunkById(250, function ($tasks) {
            $paths = [];
            foreach ($tasks as $task) {
                $current = $task; $seen = []; $depth = 0;
                while ($current !== null) {
                    if (isset($seen[$current->id]) || $depth > 8 || $current->scope_key !== $task->scope_key || $current->project_id !== $task->project_id) { throw new RuntimeException('hierarchy_backfill_invalid'); }
                    $seen[$current->id] = true;
                    $paths[] = ['scope_key' => $task->scope_key, 'ancestor_task_id' => $current->id, 'descendant_task_id' => $task->id, 'depth' => $depth++];
                    if ($current->parent_task_id === null) { break; }
                    $current = DB::table('tm_tasks')->where('id', $current->parent_task_id)->first();
                    if (!$current) { throw new RuntimeException('hierarchy_backfill_orphan'); }
                }
            }
            foreach (array_chunk($paths, 500) as $chunk) { DB::table('tm_task_closure')->insert($chunk); }
        });
    }
    public function down(): void
    {
        if (DB::table('tm_tasks')->whereNotNull('parent_task_id')->exists()) { throw new RuntimeException('hierarchy_rollback_requires_export'); }
        Schema::dropIfExists('tm_task_closure'); Schema::dropIfExists('tm_task_graph_locks');
        Schema::table('tm_projects', fn (Blueprint $t) => $t->dropColumn('hierarchy_max_depth'));
    }
};
