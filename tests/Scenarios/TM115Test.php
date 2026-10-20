<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM115Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $field=$this->fields()->create($this->manager(),'Options','multi_select',['one','two'],null,'field');$a=$this->todo('a');
        $this->error('custom_option_invalid',fn()=>$this->fields()->set($this->manager(),$a['public_key'],1,$field['field_key'],1,['bad'],'bad'));
        $this->fields()->set($this->manager(),$a['public_key'],1,$field['field_key'],1,['two','one','two'],'good'); self::assertSame(['one','two'],$this->fields()->value($this->manager(),$a['public_key'],$field['field_key'])['value']);
        self::assertSame(1,$this->queries()->tasks($this->manager(),['custom'=>[['field'=>$field['field_key'],'operator'=>'contains','value'=>['one']]]])['total']); self::assertSame(2,DB::table('tm_custom_selections')->count());
    }
    private function fields(): CustomFieldService { return $this->app->make(CustomFieldService::class); }
    private function queries(): QueryService { return $this->app->make(QueryService::class); }
}
