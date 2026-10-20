<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

abstract class EnterpriseScaleTestCase extends PhaseFiveTestCase
{
    protected function seedDataset(int $count):int
    {
        $one=$this->sales('load-one');$two=$this->workflows()->publish($this->manager('a','two'),'sales',0,'load-two',$this->definition());$pipelineOne=$this->pipeline($one['id'],name:'Load one');$pipelineTwo=$this->pipelines()->create($this->manager('a','two'),'Load two',$two['id'],['inbox'=>['state'=>'new']],'load-pipeline-two');$visible=0;$lists=[];foreach([['one','a'],['one','b'],['two','a']] as [$scope,$actor]){$lists[$scope.':'.$actor]=DB::table('tm_lists')->insertGetId(['scope_key'=>$scope,'owner_ref'=>$actor,'name'=>'Load Inbox']);}
        for($base=0;$base<$count;$base+=500){$tasks=[];$instances=[];$visits=[];$closure=[];for($offset=0;$offset<min(500,$count-$base);++$offset){$index=$base+$offset+1;$id=$index+1000;$scope=$index%10===0?'two':'one';$owner=$index%10===9?'b':'a';if($scope==='one'&&$owner==='a'){++$visible;}$public=sprintf('%08x-0000-4000-8000-%012x',$index,$index);$instance=sprintf('%08x-1111-4000-8000-%012x',$index,$index);$revision=$scope==='one'?$one['id']:$two['id'];$pipeline=$scope==='one'?$pipelineOne:$pipelineTwo;
            $tasks[]=['id'=>$id,'public_key'=>$public,'display_key'=>'LOAD-'.$index,'scope_key'=>$scope,'owner_ref'=>$owner,'list_id'=>$lists[$scope.':'.$owner],'title'=>'Load '.$index,'title_search_normalized'=>'load '.$index,'search_revision'=>2,'created_at'=>'2026-10-20 00:00:00','updated_at'=>'2026-10-20 00:00:00'];
            $instances[]=['id'=>$id,'instance_key'=>$instance,'scope_key'=>$scope,'task_id'=>$id,'subject_type'=>'task','subject_id'=>$public,'workflow_key'=>'sales','workflow_revision_id'=>$revision,'current_state_key'=>'new','slot_key'=>'main','owner_ref'=>$owner,'primary_assignee'=>$owner,'pipeline_id'=>$pipeline['id'],'active_stage_key'=>'inbox','started_at'=>'2026-10-20 00:00:00'];
            $visits[]=['scope_key'=>$scope,'instance_id'=>$id,'pipeline_id'=>$pipeline['id'],'stage_key'=>'inbox','visit_no'=>1,'entered_at'=>'2026-10-20 00:00:00'];$closure[]=['scope_key'=>$scope,'ancestor_task_id'=>$id,'descendant_task_id'=>$id,'depth'=>0];}
            DB::table('tm_tasks')->insert($tasks);DB::table('tm_workflow_instances')->insert($instances);DB::table('tm_stage_visits')->insert($visits);DB::table('tm_task_closure')->insert($closure);
        }return $visible;
    }
    protected function verifyPaging(int $size,bool $verifyInstances=false):void
    {
        $expected=$this->seedDataset($size);$queries=$this->app->make(\Karnoweb\TaskManager\Enterprise\QueryService::class);DB::flushQueryLog();DB::enableQueryLog();$memory=memory_get_usage(true);$page=$queries->tasks($this->manager(),['q'=>'load'],'id','asc',100);$next=$queries->tasks($this->manager(),['q'=>'load'],'id','asc',100,$page['cursor']);$delta=memory_get_usage(true)-$memory;$log=DB::getQueryLog();DB::disableQueryLog();
        self::assertSame($expected,$page['total']);self::assertCount(100,$page['items']);self::assertCount(100,$next['items']);self::assertSame([],array_intersect(array_column($page['items'],'id'),array_column($next['items'],'id')));self::assertLessThanOrEqual(13,count($log));self::assertLessThan(16*1024*1024,$delta);self::assertStringContainsString('limit 101',strtolower(implode('\n',array_column($log,'query'))));
        foreach([...$page['items'],...$next['items']] as $item){self::assertSame('one',$item['scope_key']);self::assertSame('a',$item['owner_ref']);}
        if($verifyInstances){$instances=$this->instances()->query($this->manager(),100);self::assertCount(100,$instances);$metrics=$this->app->make(\Karnoweb\TaskManager\Enterprise\ReportService::class)->read($this->manager())['metrics'];self::assertSame($expected,$metrics['total_instances']);self::assertSame($expected,$metrics['visit_count']);}
    }
}
