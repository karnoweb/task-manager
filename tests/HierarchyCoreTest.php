<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

final class HierarchyCoreTest extends HierarchyTestCase
{
    public function test_two_children_progress_zero_fifty_hundred_without_automatic_parent_transition(): void
    {
        $root = $this->todo('root'); $one = $this->attach($this->todo('one'), $root, 'attach-one'); $two = $this->attach($this->todo('two'), $root, 'attach-two');
        self::assertSame(0.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        $this->service()->mutate($this->manager(), $one['public_key'], $one['version'], 'done-one', 'complete');
        self::assertSame(50.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        $this->service()->mutate($this->manager(), $two['public_key'], $two['version'], 'done-two', 'complete');
        self::assertSame(100.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        self::assertSame('pending', $this->service()->get($this->manager(), $root['public_key'])['state']);
    }
    public function test_multilevel_reparent_preserves_internal_paths_and_removes_old_ancestors(): void
    {
        $a = $this->todo('a'); $b = $this->attach($this->todo('b'), $a, 'b-a'); $c = $this->attach($this->todo('c'), $b, 'c-b'); $d = $this->todo('d');
        $moved = $this->attach($b, $d, 'b-d');
        self::assertSame(0, DB::table('tm_task_closure')->where('ancestor_task_id', $a['id'])->whereIn('descendant_task_id', [$b['id'], $c['id']])->count());
        self::assertSame(2, (int) DB::table('tm_task_closure')->where('ancestor_task_id', $d['id'])->where('descendant_task_id', $c['id'])->value('depth'));
        self::assertSame(1, (int) DB::table('tm_task_closure')->where('ancestor_task_id', $b['id'])->where('descendant_task_id', $c['id'])->value('depth'));
        self::assertGreaterThan($b['version'], $moved['version']); self::assertSame(3, $this->hierarchy()->tree($this->manager(), $d['public_key'])['total']);
    }
    public function test_self_and_distant_descendant_cycles_roll_back_the_whole_command(): void
    {
        $a = $this->todo('a'); $b = $this->attach($this->todo('b'), $a, 'b-a'); $c = $this->attach($this->todo('c'), $b, 'c-b');
        $before = DB::table('tm_task_closure')->count();
        $this->error('task_hierarchy_cycle', fn () => $this->hierarchy()->reparent($this->manager(), $a['public_key'], $a['version'], $a['public_key'], $a['version'], 'self'));
        $this->error('task_hierarchy_cycle', fn () => $this->attach($a, $c, 'cycle'));
        self::assertSame($before, DB::table('tm_task_closure')->count()); self::assertNull($this->service()->get($this->manager(), $a['public_key'])['parent_task_id']);
    }
    public function test_default_depth_three_and_project_limit_eight_are_real_subtree_constraints(): void
    {
        $root = $this->todo('root'); $parent = $root;
        for ($i = 1; $i <= 3; ++$i) { $parent = $this->attach($this->todo('child-'.$i), $parent, 'attach-'.$i); }
        $extra = $this->todo('extra'); $this->error('task_depth_exceeded', fn () => $this->attach($extra, $parent, 'too-deep'));
        [$revision, $project] = $this->projectTask(); $project = $this->hierarchy()->configureDepth($this->manager(), $project['project_key'], $project['version'], 8, 'depth-eight');
        $parent = $this->inProject($project, 'project-root');
        for ($i = 1; $i <= 8; ++$i) { $parent = $this->attach($this->inProject($project, 'project-child-'.$i), $parent, 'project-attach-'.$i); }
        self::assertSame(8, (int) DB::table('tm_task_closure')->where('descendant_task_id', $parent['id'])->max('depth'));
        $this->error('task_depth_exceeded', fn () => $this->hierarchy()->configureDepth($this->manager(), $project['project_key'], $project['version'], 3, 'lower-depth'));
    }
    public function test_parent_requires_same_project_but_not_same_workflow_revision(): void
    {
        [$revision, $project, $root] = $this->projectTask();
        $bug = $this->workflows()->publish($this->manager(), 'bug', 0, 'bug-workflow', $this->definition());
        $project = $this->projects()->change($this->manager(), $project['project_key'], 1, 'bug-type', 'type_workflow', ['type' => 'bug', 'revision' => $bug['id']]);
        $child = $this->attach($this->inProject($project, 'bug-child', 'bug'), $root, 'bug-parent');
        self::assertSame($root['id'], $child['parent_task_id']);
        self::assertNotSame(DB::table('tm_workflow_instances')->where('task_id', $root['id'])->value('workflow_revision_id'), DB::table('tm_workflow_instances')->where('task_id', $child['id'])->value('workflow_revision_id'));
        $other = $this->todo('personal'); $this->error('parent_project_mismatch', fn () => $this->attach($other, $root, 'wrong-project'));
    }
    public function test_parent_delete_defaults_to_denial_and_cascade_is_previewed_guarded_and_replay_safe(): void
    {
        $root = $this->todo('root'); $child = $this->attach($this->todo('child'), $root, 'attach');
        $this->error('parent_has_children', fn () => $this->service()->mutate($this->manager(), $root['public_key'], 1, 'delete-root', 'delete'));
        $preview = $this->hierarchy()->previewDelete($this->manager(), $root['public_key'], 'cascade_soft_delete');
        $this->hierarchy()->executeDelete($this->manager(), $preview, 'cascade'); $events = DB::table('tm_outbox')->count();
        $this->hierarchy()->executeDelete($this->manager(), $preview, 'cascade');
        self::assertSame(2, DB::table('tm_tasks')->whereNotNull('deleted_at')->count()); self::assertSame($events, DB::table('tm_outbox')->count());
        $this->error('parent_deleted', fn () => $this->service()->mutate($this->manager(), $child['public_key'], $child['version'] + 1, 'restore-child', 'undelete'));
        self::assertSame(3, DB::table('tm_task_closure')->count());
    }
    public function test_detach_delete_preserves_descendant_subtree_and_rejects_stale_preview(): void
    {
        $root = $this->todo('root'); $child = $this->attach($this->todo('child'), $root, 'attach'); $leaf = $this->attach($this->todo('leaf'), $child, 'attach-leaf');
        $preview = $this->hierarchy()->previewDelete($this->manager(), $root['public_key'], 'detach');
        $this->service()->mutate($this->manager(), $leaf['public_key'], $leaf['version'], 'rename', 'update', ['title' => 'changed']);
        $this->error('preview_stale', fn () => $this->hierarchy()->executeDelete($this->manager(), $preview, 'stale'));
        $preview = $this->hierarchy()->previewDelete($this->manager(), $root['public_key'], 'detach'); $this->hierarchy()->executeDelete($this->manager(), $preview, 'detach');
        self::assertNull($this->service()->get($this->manager(), $child['public_key'])['parent_task_id']); self::assertSame($child['id'], $this->service()->get($this->manager(), $leaf['public_key'])['parent_task_id']);
        self::assertSame(1, DB::table('tm_tasks')->whereNotNull('deleted_at')->count());
    }
    public function test_tree_progress_and_reparent_do_not_grant_a_shared_childs_hidden_parent_access(): void
    {
        $root = $this->todo('root'); $child = $this->attach($this->todo('child'), $root, 'attach'); $this->service()->share($this->manager(), $child['public_key'], $child['version'], 'share-child', 'b');
        $this->error('not_found', fn () => $this->hierarchy()->tree($this->manager('b'), $root['public_key']));
        self::assertSame(1, $this->hierarchy()->tree($this->manager('b'), $child['public_key'])['total']);
        $this->error('not_found', fn () => $this->hierarchy()->reparent($this->manager('b'), $child['public_key'], $child['version'] + 1, $root['public_key'], 1, 'hidden-parent'));
    }
}
