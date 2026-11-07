<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Exceptions\DomainError;

final class PipelineAdministrationTest extends WorkflowTestCase
{
    public function test_pipeline_rename_is_versioned_and_replayable(): void
    {
        $revision = $this->sales();
        $pipeline = $this->pipeline($revision['id']);
        $renamed = $this->pipelines()->rename($this->manager(), $pipeline['pipeline_key'], $pipeline['version'], 'Enterprise sales', 'rename');
        self::assertSame('Enterprise sales', $renamed['name']);
        self::assertSame($pipeline['version'] + 1, $renamed['version']);
        self::assertSame($renamed, $this->pipelines()->rename($this->manager(), $pipeline['pipeline_key'], $pipeline['version'], 'Enterprise sales', 'rename'));
        $this->expectExceptionMessage('version_conflict');
        $this->pipelines()->rename($this->manager(), $pipeline['pipeline_key'], $pipeline['version'], 'Stale edit', 'stale');
    }

    public function test_capacity_changes_are_enforced_by_transitions(): void
    {
        $revision = $this->sales();
        $pipeline = $this->pipeline($revision['id']);
        $updated = $this->pipelines()->setStageCapacity($this->manager(), $pipeline['pipeline_key'], $pipeline['version'], 'review', 1, 'capacity');
        self::assertSame(1, $updated['stages'][1]['capacity']);
        $this->host('first');
        $this->host('second');
        $first = $this->instances()->start($this->manager(), 'crm.lead', 'first', $revision['id'], 'start-first', pipelineKey: $pipeline['pipeline_key']);
        $second = $this->instances()->start($this->manager(), 'crm.lead', 'second', $revision['id'], 'start-second', pipelineKey: $pipeline['pipeline_key']);
        $this->instances()->transition($this->manager(), $first['instance_key'], $first['version'], 'move-first', 'review');
        $this->expectException(DomainError::class);
        $this->expectExceptionMessage('capacity_exceeded');
        $this->instances()->transition($this->manager(), $second['instance_key'], $second['version'], 'move-second', 'review');
    }
}
