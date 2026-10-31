<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\StateIndex;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('workflow_states'), function (Blueprint $t) {
            $t->collation = IdentifierCollation::forConnection(Schema::getConnection());
            $t->foreignId('workflow_revision_id')->constrained(Tables::name('workflow_definitions'))->restrictOnDelete();
            $t->string('state_key', 100);
            $t->string('terminal', 30);
            $t->string('category', 30);
            $t->primary(['workflow_revision_id', 'state_key']);
            $t->index(['category', 'workflow_revision_id', 'state_key'], Tables::name('workflow_category_lookup'));
        });
        Schema::table(Tables::name('instance_transitions'), function (Blueprint $t) {
            $t->foreignId('from_revision_id')->nullable()->constrained(Tables::name('workflow_definitions'))->restrictOnDelete();
            $t->foreignId('to_revision_id')->nullable()->constrained(Tables::name('workflow_definitions'))->restrictOnDelete();
            $t->string('from_category', 30)->nullable();
            $t->string('to_category', 30)->nullable();
            $t->index(['instance_id', 'to_category', 'id'], Tables::name('transition_category_lookup'));
        });
        DB::table(Tables::name('workflow_definitions'))->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                StateIndex::publish(DB::connection(), $row->id, Definition::decode($row->definition));
            }
        });
        // Old transitions retain unknown category/revision: current revision is not historical proof.
    }

    public function down(): void
    {
        if (DB::table(Tables::name('instance_transitions'))->whereNotNull('to_revision_id')->exists()) {
            throw new RuntimeException('state_history_rollback_requires_export');
        }
        // InnoDB may replace the implicit instance FK index with the composite index.
        // Restore a supporting index before removing that composite during rollback.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $supported = false;
            foreach (Schema::getIndexes(Tables::name('instance_transitions')) as $index) {
                if ($index['name'] !== Tables::name('transition_category_lookup') && ($index['columns'][0] ?? null) === 'instance_id') {
                    $supported = true;
                }
            }
            if (! $supported) {
                Schema::table(Tables::name('instance_transitions'), fn (Blueprint $t) => $t->index('instance_id', Tables::name('transition_instance_fk_rollback')));
            }
        }
        Schema::table(Tables::name('instance_transitions'), function (Blueprint $t) {
            $t->dropForeign(['from_revision_id']);
            $t->dropForeign(['to_revision_id']);
            $t->dropIndex(Tables::name('transition_category_lookup'));
            $t->dropColumn(['from_revision_id', 'to_revision_id', 'from_category', 'to_category']);
        });
        Schema::dropIfExists(Tables::name('workflow_states'));
    }
};
