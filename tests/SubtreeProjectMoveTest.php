<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

final class SubtreeProjectMoveTest extends AgileTestCase
{
    public function test_controlled_subtree_move_preserves_internal_paths_and_public_keys_and_rejects_stale_preview(): void
    {
        [$revision, $source, $root] = $this->projectTask();
        $child = $this->inProject($source, 'child');
        $grandchild = $this->inProject($source, 'grandchild');
        $this->attach($child, $root, 'child-root');
        $this->attach($grandchild, $child, 'grandchild-child');
        $target = $this->projects()->create($this->manager(), 'DST', 'Destination', $revision['id'], 'destination');
        $preview = $this->projects()->previewSubtreeMove($this->manager(), $root['public_key'], $target['project_key'], ['task' => ['new' => 'new']]);
        self::assertSame(3, count($preview['items']));
        self::assertSame($source['id'], $this->service()->get($this->manager(), $root['public_key'])['project_id']);
        $grandchild = $this->service()->get($this->manager(), $grandchild['public_key']);
        $this->service()->mutate($this->manager(), $grandchild['public_key'], $grandchild['version'], 'change-child', 'update', ['title' => 'New']);
        $this->error('preview_stale', fn () => $this->projects()->moveSubtree($this->manager(), $preview, 'stale'));
        $preview = $this->projects()->previewSubtreeMove($this->manager(), $root['public_key'], $target['project_key'], ['task' => ['new' => 'new']]);
        $result = $this->projects()->moveSubtree($this->manager(), $preview, 'move-subtree');
        self::assertSame(3, count($result['task_keys']));
        foreach ([$root, $child, $grandchild] as $task) {
            self::assertSame($target['id'], $this->service()->get($this->manager(), $task['public_key'])['project_id']);
        }
        self::assertSame($child['id'], $this->service()->get($this->manager(), $grandchild['public_key'])['parent_task_id']);
        self::assertSame(2, DB::table('tm_task_closure')->where('ancestor_task_id', $root['id'])->where('descendant_task_id', $grandchild['id'])->value('depth'));
        self::assertSame($result, $this->projects()->moveSubtree($this->manager(), $preview, 'move-subtree'));
    }

    public function test_subtree_move_never_skips_source_permissions_or_active_sprint_memberships(): void
    {
        [$revision, $source, $root] = $this->projectTask();
        $child = $this->inProject($source, 'child');
        $this->attach($child, $root, 'parent');
        $target = $this->projects()->create($this->manager(), 'DST', 'Destination', $revision['id'], 'target');
        $sprint = $this->sprints()->create($this->manager(), $source['project_key'], 'Sprint', null, null, 'sprint');
        $this->enrollTasks($sprint, [$child], 'enroll');
        $this->error('sprint_membership_blocks_move', fn () => $this->projects()->previewSubtreeMove($this->manager(), $root['public_key'], $target['project_key'], ['task' => ['new' => 'new']]));
        self::assertSame(2, DB::table('tm_tasks')->where('project_id', $source['id'])->count());
        self::assertSame(0, DB::table('tm_tasks')->where('project_id', $target['id'])->count());
    }
}
