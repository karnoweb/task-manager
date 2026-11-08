<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM228Test extends GroupTestCase
{
    public function test_tm228(): void
    {
        [$group, $instances, $pipeline] = $this->campaignFixture();
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $this->host('new-lead');
        $added = $this->instances()->start($this->manager(), 'crm.lead', 'new-lead', $instances[0]['workflow_revision_id'], 'new-member', pipelineKey: $pipeline['pipeline_key']);
        $this->groupService()->enroll($this->manager(), $group['group_key'], $group['version'], [['instance_key' => $added['instance_key'], 'version' => 1]], 'add-member');
        try {
            $this->groupService()->executeClosure($this->manager(), $preview, 'stale');
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('preview_stale', $error->errorCode);
        }
        self::assertSame(0, DB::table('tm_group_operations')->count());
        self::assertSame(1, $this->groupService()->previewDelta($this->manager(), $preview)['counts_delta']['failed']);
    }
}
