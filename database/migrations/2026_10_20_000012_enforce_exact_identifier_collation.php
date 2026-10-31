<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Schema\IdentifierCollation;
use Karnoweb\TaskManager\Schema\Tables;

return new class extends Migration
{
    private const TABLES = [
        'active_approval_steps', 'active_external_sagas', 'active_group_members', 'active_handoff_origins', 'active_process_slots',
        'approval_policies', 'approval_steps', 'approval_votes', 'assignment_policies', 'assignment_queues', 'assignment_slots',
        'attachments', 'audit_events', 'case_subjects', 'command_journal', 'comments', 'external_case_subjects', 'external_sagas',
        'group_lifecycle_policies', 'group_operation_items', 'group_operations', 'handoff_groups', 'handoff_outcomes', 'handoff_repairs',
        'handoffs', 'instance_assignments', 'instance_transitions', 'lists', 'milestone_intents', 'outbox', 'pipeline_stages',
        'pipelines', 'process_cases', 'process_group_members', 'process_groups', 'process_milestones', 'project_members',
        'project_type_workflows', 'projects', 'security_decisions', 'sequences', 'sla_events', 'sla_pauses', 'stage_policies',
        'stage_visits', 'subject_conversions', 'task_grants', 'task_key_aliases', 'tasks', 'workflow_definitions', 'workflow_heads', 'workflow_instances',
    ];

    public function up(): void
    {
        $db = DB::connection();
        if (! $db instanceof MySqlConnection) {
            return;
        }
        $collation = IdentifierCollation::forConnection($db);
        $prefix = $db->getTablePrefix();
        $physical = array_map(fn ($table) => $prefix.Tables::name($table), self::TABLES);
        $tables = $db->query()->fromRaw('information_schema.TABLES')->where('TABLE_SCHEMA', $db->getDatabaseName())->whereIn('TABLE_NAME', $physical)->get(['TABLE_NAME', 'TABLE_COLLATION']);
        $columns = $db->query()->fromRaw('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db->getDatabaseName())->whereIn('TABLE_NAME', $physical)
            ->whereNotNull('COLLATION_NAME')->where('COLLATION_NAME', '!=', $collation)->distinct()->pluck('TABLE_NAME')->all();
        $changes = $tables->filter(fn ($row) => $row->TABLE_COLLATION !== $collation || in_array($row->TABLE_NAME, $columns, true));
        if ($changes->isEmpty()) {
            return;
        }
        $checks = (int) $db->selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled;
        try {
            $db->statement('SET SESSION foreign_key_checks = 0');
            foreach ($changes as $table) {
                $logical = substr($table->TABLE_NAME, strlen($prefix));
                $db->statement('ALTER TABLE '.$db->getQueryGrammar()->wrapTable($logical).' CONVERT TO CHARACTER SET utf8mb4 COLLATE '.$collation);
            }
        } finally {
            $db->statement('SET SESSION foreign_key_checks = '.$checks);
        }
    }

    public function down(): void
    {
        // Never restore case/padding-insensitive comparisons to security identifiers.
    }
};
