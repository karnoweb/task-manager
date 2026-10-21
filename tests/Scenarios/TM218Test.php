<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Contracts\ExportStorage;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\Tests\EnterpriseProcessTestCase;

final class TM218Test extends EnterpriseProcessTestCase
{
    public function test_scenario(): void
    {
        [$group,$instances] = $this->campaignFixture(3);
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        $this->processAll($run['run_key']);
        DB::table('host_permissions')->where('subject_id', 'lead-1')->where('actor', 'a')->delete();
        DB::table('host_subjects')->where('id', 'lead-0')->update(['id' => '=FORMULA()']);
        DB::table('host_permissions')->where('subject_id', 'lead-0')->update(['subject_id' => '=FORMULA()']);
        DB::table('tm_workflow_instances')->where('id', $instances[0]['id'])->update(['subject_id' => '=FORMULA()']);
        $exports = $this->app->make(ExportService::class);
        $definition = ['source' => 'group_closure', 'group_key' => $group['group_key'], 'format' => 'csv', 'columns' => ['subject_id', 'result']];
        $csv = implode('', iterator_to_array($exports->stream($this->manager(), $definition)));
        self::assertStringContainsString("'=FORMULA()", $csv);
        self::assertStringNotContainsString('lead-1', $csv);
        self::assertStringContainsString('lead-2', $csv);
        $job = $exports->enqueue($this->manager(), $definition, 'export');
        $ready = $exports->build($this->worker(), $job['export_key'], 1);
        self::assertSame('ready', $ready['state']);
        DB::table('host_permissions')->where('subject_id', 'lead-2')->where('actor', 'a')->delete();
        $this->error('export_permission_revoked', fn () => $exports->download($this->manager(), $job['export_key']));
        $reference = DB::table('tm_exports')->where('id', $job['id'])->value('file_ref');
        $this->app->make(ExportStorage::class)->delete($this->manager(),$reference);
    }
}
