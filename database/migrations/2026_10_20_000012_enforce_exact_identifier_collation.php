<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

return new class extends Migration {
    private const TABLES = [
        'tm_active_approval_steps', 'tm_active_external_sagas', 'tm_active_group_members', 'tm_active_handoff_origins', 'tm_active_process_slots',
        'tm_approval_policies', 'tm_approval_steps', 'tm_approval_votes', 'tm_assignment_policies', 'tm_assignment_queues', 'tm_assignment_slots',
        'tm_attachments', 'tm_audit_events', 'tm_case_subjects', 'tm_command_journal', 'tm_comments', 'tm_external_case_subjects', 'tm_external_sagas',
        'tm_group_lifecycle_policies', 'tm_group_operation_items', 'tm_group_operations', 'tm_handoff_groups', 'tm_handoff_outcomes', 'tm_handoff_repairs',
        'tm_handoffs', 'tm_instance_assignments', 'tm_instance_transitions', 'tm_lists', 'tm_milestone_intents', 'tm_outbox', 'tm_pipeline_stages',
        'tm_pipelines', 'tm_process_cases', 'tm_process_group_members', 'tm_process_groups', 'tm_process_milestones', 'tm_project_members',
        'tm_project_type_workflows', 'tm_projects', 'tm_security_decisions', 'tm_sequences', 'tm_sla_events', 'tm_sla_pauses', 'tm_stage_policies',
        'tm_stage_visits', 'tm_subject_conversions', 'tm_task_grants', 'tm_task_key_aliases', 'tm_tasks', 'tm_workflow_definitions', 'tm_workflow_heads', 'tm_workflow_instances',
    ];
    public function up(): void
    {
        $db = DB::connection();
        if (!$db instanceof MySqlConnection) { return; }
        $collation = IdentifierCollation::forConnection($db); $prefix = $db->getTablePrefix();
        $physical = array_map(fn ($table) => $prefix.$table, self::TABLES);
        $tables = $db->query()->fromRaw('information_schema.TABLES')->where('TABLE_SCHEMA', $db->getDatabaseName())->whereIn('TABLE_NAME', $physical)->get(['TABLE_NAME', 'TABLE_COLLATION']);
        $columns = $db->query()->fromRaw('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db->getDatabaseName())->whereIn('TABLE_NAME', $physical)
            ->whereNotNull('COLLATION_NAME')->where('COLLATION_NAME', '!=', $collation)->distinct()->pluck('TABLE_NAME')->all();
        $changes = $tables->filter(fn ($row) => $row->TABLE_COLLATION !== $collation || in_array($row->TABLE_NAME, $columns, true));
        if ($changes->isEmpty()) { return; }
        $checks = (int) $db->selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled;
        try {
            $db->statement('SET SESSION foreign_key_checks = 0');
            foreach ($changes as $table) {
                $logical = substr($table->TABLE_NAME, strlen($prefix));
                $db->statement('ALTER TABLE '.$db->getQueryGrammar()->wrapTable($logical).' CONVERT TO CHARACTER SET utf8mb4 COLLATE '.$collation);
            }
        } finally { $db->statement('SET SESSION foreign_key_checks = '.$checks); }
    }
    public function down(): void
    {
        // Never restore case/padding-insensitive comparisons to security identifiers.
    }
};
