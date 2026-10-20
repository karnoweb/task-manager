<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Enterprise\QueryService;

final class EnterpriseQueryTest extends PhaseFiveTestCase
{
    private function fields(): CustomFieldService { return $this->app->make(CustomFieldService::class); }
    private function queries(): QueryService { return $this->app->make(QueryService::class); }
    public function test_typed_fields_filter_sort_and_archive_without_destroying_values(): void
    {
        $field=$this->fields()->create($this->manager(),'Cost','number',[],null,'field'); $date=$this->fields()->create($this->manager(),'Date','date',[],null,'date-field'); $a=$this->todo('a');$b=$this->todo('b');
        $aValue=$this->fields()->set($this->manager(),$a['public_key'],1,$field['field_key'],1,'10.5','a-cost'); $this->fields()->set($this->manager(),$b['public_key'],1,$field['field_key'],1,'2','b-cost');
        $this->fields()->set($this->manager(),$a['public_key'],$aValue['task_version'],$date['field_key'],1,'2026-10-25','a-date');
        $result=$this->queries()->tasks($this->manager(),[],'custom:'.$field['field_key']); self::assertSame([$b['public_key'],$a['public_key']],array_column($result['items'],'public_key'));
        self::assertSame(1,$this->queries()->tasks($this->manager(),['custom'=>[['field'=>$field['field_key'],'operator'=>'>','value'=>'5']]])['total']);
        self::assertSame(1,$this->queries()->tasks($this->manager(),['custom'=>[['field'=>$date['field_key'],'operator'=>'<','value'=>'2026-11-01']]])['total']);
        $this->error('custom_number_invalid',fn()=>$this->fields()->set($this->manager(),$a['public_key'],3,$field['field_key'],1,'NaN','bad'));
        $this->fields()->archive($this->manager(),$field['field_key'],1,true,'archive'); self::assertSame('10.500000',$this->fields()->value($this->manager(),$a['public_key'],$field['field_key'])['value']); self::assertCount(1,$this->fields()->forms($this->manager()));
        $this->error('not_found',fn()=>$this->fields()->value($this->manager('b'),$a['public_key'],$field['field_key']));
    }
    public function test_multiselect_rejects_unknown_options_and_uses_indexed_membership(): void
    {
        $field=$this->fields()->create($this->manager(),'Options','multi_select',['one','two'],null,'field');$a=$this->todo('a');
        $this->error('custom_option_invalid',fn()=>$this->fields()->set($this->manager(),$a['public_key'],1,$field['field_key'],1,['bad'],'bad'));
        $this->fields()->set($this->manager(),$a['public_key'],1,$field['field_key'],1,['two','one','two'],'good'); self::assertSame(['one','two'],$this->fields()->value($this->manager(),$a['public_key'],$field['field_key'])['value']);
        self::assertSame(1,$this->queries()->tasks($this->manager(),['custom'=>[['field'=>$field['field_key'],'operator'=>'contains','value'=>['one']]]])['total']); self::assertSame(2,DB::table('tm_custom_selections')->count());
    }
    public function test_persian_search_and_cursor_visibility_are_filtered_before_counts_and_pages(): void
    {
        $a=$this->service()->create($this->manager(),'كتاب ۱۲ نيم‌فاصله','a');$b=$this->service()->create($this->manager(),'کتاب 12 نیم فاصله','b');$hidden=$this->service()->create($this->manager('b'),'کتاب 12 نیم فاصله','hidden');
        $page=$this->queries()->tasks($this->manager(),['q'=>'کتاب 12 نیم فاصله'],'id','asc',1);self::assertSame(2,$page['total']);self::assertCount(1,$page['items']);self::assertNotNull($page['cursor']);
        self::assertSame([$b['public_key']],array_column($this->queries()->tasks($this->manager(),['q'=>'كتاب ۱۲ نيم‌فاصله'],'id','asc',1,$page['cursor'])['items'],'public_key'));
        $this->service()->share($this->manager(),$a['public_key'],1,'share','b');
        $this->error('cursor_stale',fn()=>$this->queries()->tasks($this->manager(),['q'=>'کتاب 12 نیم فاصله'],'id','asc',1,$page['cursor']));
        self::assertSame(2,$this->queries()->tasks($this->manager('b'),['q'=>'كتاب 12 نیم فاصله'])['total']); self::assertSame('كتاب ۱۲ نيم‌فاصله',$this->service()->get($this->manager(),$a['public_key'])['title']);
    }
}
