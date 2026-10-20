<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM141Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $a=$this->todo('=HYPERLINK("bad")');$this->service()->create($this->manager('b'),'PRIVATE','private');$csv=implode('',iterator_to_array($this->exports()->stream($this->manager(),['format'=>'csv','columns'=>['title']])));self::assertStringContainsString("'=HYPERLINK",$csv);self::assertStringNotContainsString('PRIVATE',$csv);
        $json=implode('',iterator_to_array($this->exports()->stream($this->manager(),['format'=>'json','columns'=>['public_key','title']])));$data=json_decode($json,true,flags:JSON_THROW_ON_ERROR);self::assertCount(1,$data);self::assertSame($a['public_key'],$data[0]['public_key']);
    }
    private function exports():ExportService{return $this->app->make(ExportService::class);}
    private function worker(string $actor='a'):ExecutionContext{$base=$this->manager($actor);return new ExecutionContext('one',$actor,'export-worker',true,$base->capabilities);}
}
