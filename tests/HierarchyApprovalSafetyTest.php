<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;

final class HierarchyApprovalSafetyTest extends ApprovalTestCase
{
    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope); return new ExecutionContext($scope, $actor, 'hierarchy-approval', capabilities: [...$base->capabilities, 'task.reparent']);
    }
    public function test_child_pending_approval_blocks_parent_completion_and_atomic_subtree_terminal_mapping(): void
    {
        $parent = $this->service()->create($this->manager(), 'Parent', 'parent'); $child = $this->service()->create($this->manager(), 'Child', 'child');
        $this->app->make(HierarchyService::class)->reparent($this->manager(), $child['public_key'], 1, $parent['public_key'], 1, 'attach');
        $revision = $this->sales(); $pipeline = $this->pipeline($revision['id']); $policy = $this->approvalPolicy(); $policy['approvers'] = ['b']; $policy['quorum'] = 1;
        $this->approvals()->configureStage($this->manager(), $pipeline['pipeline_key'], 'review', 1, 'policy', $policy);
        $child = $this->service()->get($this->manager(), $child['public_key']); $this->service()->share($this->manager(), $child['public_key'], $child['version'], 'share-child', 'b');
        $instance = $this->instances()->start($this->manager(), 'task', $child['public_key'], $revision['id'], 'start-child-review', 'legal_review', $pipeline['pipeline_key']);
        $this->instances()->transition($this->manager(), $instance['instance_key'], 1, 'review', 'review');
        self::assertSame(1, DB::table('tm_active_approval_steps')->count());
        $this->error('child_approval_pending', fn () => $this->service()->mutate($this->manager(), $parent['public_key'], 1, 'complete-parent', 'complete'));
        $project = $this->projects()->create($this->manager(), 'DST', 'Destination', $revision['id'], 'project');
        $preview = $this->projects()->previewSubtreeMove($this->manager(), $parent['public_key'], $project['project_key'], ['task' => ['pending' => 'won']]); $events = DB::table('tm_outbox')->count();
        $this->error('child_approval_pending', fn () => $this->projects()->moveSubtree($this->manager(), $preview, 'move'));
        self::assertSame(0, DB::table('tm_tasks')->whereNotNull('project_id')->count()); self::assertSame($events, DB::table('tm_outbox')->count()); self::assertSame('pending', $this->service()->get($this->manager(), $parent['public_key'])['state']);
    }
}
