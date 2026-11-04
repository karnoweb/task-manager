<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ScopedSubject;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Workflow\TaskSubjectHandler;

final class ScopedSubjectTest extends WorkflowTestCase
{
    public function test_host_subject_can_store_the_branch_id_instead_of_the_engine_scope(): void
    {
        DB::table('host_subjects')->insert([
            'id' => 'acct-7',
            'scope_key' => '7',
            'type' => 'crm.account',
            'title' => 'Club seven',
        ]);
        DB::table('host_permissions')->insert(['subject_id' => 'acct-7', 'actor' => 'a', 'can_edit' => true]);
        $this->app->bind(SubjectRegistry::class, fn () => new SubjectRegistry([
            'task' => new TaskSubjectHandler($this->service()),
            'crm.account' => new NumericBranchSubject(DB::connection()),
        ]));

        $context = $this->manager('a', 'branch:7');
        $revision = $this->workflows()->publish($context, 'sales', 0, 'workflow', $this->definition());
        $pipeline = $this->pipelines()->create($context, 'Sales', $revision['id'], [
            'inbox' => ['state' => 'new'],
            'review' => ['state' => 'review'],
            'won' => ['state' => 'won'],
        ], 'pipeline-numeric');

        $instance = $this->instances()->start($context, 'crm.account', 'acct-7', $revision['id'], 'start', pipelineKey: $pipeline['pipeline_key']);

        self::assertSame('acct-7', $instance['subject_id']);
        self::assertSame('branch:7', $instance['scope_key'] ?? DB::table('tm_workflow_instances')->where('instance_key', $instance['instance_key'])->value('scope_key'));
    }
}

final class NumericBranchSubject implements SubjectHandler, ScopedSubject
{
    public function __construct(private readonly Connection $db) {}

    public function scopeValue(ExecutionContext $context): string
    {
        return str_starts_with($context->scope, 'branch:') ? substr($context->scope, 7) : $context->scope;
    }

    public function query(ExecutionContext $context, string $capability): Builder
    {
        return $this->db->table('host_subjects')->where('type', 'crm.account')->whereExists(function (Builder $query) use ($context, $capability) {
            $query->selectRaw('1')->from('host_permissions')->whereColumn('subject_id', 'host_subjects.id')->where('actor', $context->actor);
            if ($capability === 'edit') {
                $query->where('can_edit', true);
            }
        });
    }

    public function idColumn(): string
    {
        return 'id';
    }

    public function scopeColumn(): string
    {
        return 'scope_key';
    }

    public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void {}
}
