<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Enterprise\ReportService;

final class ReportRevocationTest extends EnterpriseProcessTestCase
{
    public function test_external_source_revocation_invalidates_cached_and_journal_replayed_aggregates():void
    {
        [$origin]=$this->handoffFixture(false);$reports=$this->app->make(ReportService::class);$built=$reports->rebuild($this->manager(),[],'build');self::assertSame(1,$built['metrics']['total_instances']);self::assertSame('projected',$reports->read($this->manager())['state']);
        DB::table('host_permissions')->where('actor','a')->where('subject_id','lead-1')->delete();self::assertSame('stale',$reports->read($this->manager(),[],false)['state']);self::assertSame(0,$reports->read($this->manager())['metrics']['total_instances']);$this->error('report_permission_revoked',fn()=>$reports->rebuild($this->manager(),[],'build'));
    }
}
