<?php
namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\{SubjectHandler,ResponsibilityResolver};
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\UI\Crm\{HostAccess,DealSubject,LeadSubject};
use Karnoweb\TaskManager\Tests\Support\{HostResponsibilityResolver,PhaseSixTicket,PhaseSixTicketRouter};

abstract class RealHostE2ETestCase extends PhaseSixTestCase
{
    protected function manager(string $actor='1',string $scope='1'):ExecutionContext{$c=parent::manager($actor,$scope);return new ExecutionContext($scope,$actor,'real-host-e2e',capabilities:[...$c->capabilities,'approval.manage','approval.vote','approval.execute','sla.manage','sla.pause','sla.run','handoff.recall','handoff.run','group.view','group.manage','group.preview','group.execute','milestone.verify']);}
    protected function setUp():void
    {
        parent::setUp();
        Schema::create('phase_six_acl',function(Blueprint $t){$t->string('type');$t->string('subject_id');$t->string('actor');$t->boolean('edit')->default(true);});
        Schema::create('phase_six_tickets',function(Blueprint $t){$t->id();$t->foreignId('lead_id')->constrained('crm_leads');$t->unsignedBigInteger('referred_deal_id')->nullable();$t->string('branch_id');$t->string('owner_ref');$t->string('department')->default('support');$t->text('private_message');$t->timestamps();});
        Schema::create('host_people',function(Blueprint $t){$t->string('scope_key');$t->string('actor');$t->string('team');$t->boolean('active')->default(true);$t->boolean('can_assign')->default(true);$t->boolean('can_approve')->default(true);});
        foreach(['1','2','3','4'] as $actor){DB::table('host_people')->insert(['scope_key'=>'1','actor'=>$actor,'team'=>(int)$actor<3?'sales':'contracts']);}
        $this->app->instance(ResponsibilityResolver::class,new HostResponsibilityResolver(DB::connection()));
        $access=new class implements HostAccess{
            public function constrain(Builder $q,ExecutionContext $c,string $type,string $capability):void{$table=$type==='crm.lead'?'crm_leads':'crm_deals';$q->where($table.'.branch_id',$c->scope)->where(function(Builder $a)use($table,$c,$type,$capability){$a->where($table.'.assigned_to',$c->actor)->orWhereExists(function(Builder $g)use($table,$c,$type,$capability){$g->selectRaw('1')->from('phase_six_acl')->whereColumn('phase_six_acl.subject_id',$table.'.id')->where('type',$type)->where('actor',$c->actor);if($capability==='edit'){$g->where('edit',true);}});});}
            public function scopeFor(object $model):string{return (string)($model->branch_id??$model->subject?->branch_id??'missing');}
        };$this->app->instance(HostAccess::class,$access);
        $ticket=new class implements SubjectHandler{
            public function idColumn():string{return 'phase_six_tickets.id';}public function scopeColumn():string{return 'phase_six_tickets.branch_id';}
            public function query(ExecutionContext $c,string $capability):Builder{return DB::table('phase_six_tickets')->where('branch_id',$c->scope)->where(fn($q)=>$q->where('owner_ref',$c->actor)->orWhere(fn($r)=>$r->where('department','sales')->whereRaw('? = ?',['2',$c->actor])));}
            public function guard(ExecutionContext $c,string $id,array $instance,string $action,array $payload):void{if(!$this->query($c,'edit')->where('id',$id)->exists()){throw new \Karnoweb\TaskManager\Exceptions\DomainError('not_found');}}
        };
        $this->app->bind(SubjectRegistry::class,fn()=>new SubjectRegistry(['task'=>new \Karnoweb\TaskManager\Workflow\TaskSubjectHandler($this->service()),'crm.deal'=>new DealSubject(DB::connection(),$access),'crm.lead'=>new LeadSubject(DB::connection(),$access),'support.ticket'=>$ticket]));
    }
    protected function grantDeal(int $id,array $actors):void{foreach($actors as $actor){DB::table('phase_six_acl')->insert(['type'=>'crm.deal','subject_id'=>(string)$id,'actor'=>$actor]);}}
    protected function ticketFlow():array
    {
        [$lead,$deal,$stages]=$this->crm();$t=PhaseSixTicket::create(['lead_id'=>$lead->id,'branch_id'=>'1','owner_ref'=>'1','private_message'=>'PRIVATE SUPPORT MESSAGE']);
        $d=$this->definition();$d['states']['referred']=['terminal'=>'success'];$d['transitions']['refer']=['from'=>'new','to'=>'referred','reason_required'=>true,'reason_codes'=>['sales_opportunity_identified']];$r=$this->workflows()->publish($this->manager(),'tickets',0,'ticket-workflow',$d);
        $stageMap=['inbox'=>['state'=>'new'],'review'=>['state'=>'review'],'won'=>['state'=>'won'],'referred'=>['state'=>'referred']];$p=$this->pipelines()->create($this->manager(),'Support',$r['id'],$stageMap,'support');$sales=$this->pipelines()->create($this->manager(),'Sales',$r['id'],$stageMap,'sales');
        $router=new PhaseSixTicketRouter(DB::connection(),$deal->pipeline_id,$stages[0]->id);$this->app->instance(\Karnoweb\TaskManager\Handoff\SubjectRouteRegistry::class,new \Karnoweb\TaskManager\Handoff\SubjectRouteRegistry(['ticket.sales-v1'=>$router]));
        $origin=$this->instances()->start($this->manager(),'support.ticket',(string)$t->id,$r['id'],'ticket-start',pipelineKey:$p['pipeline_key']);return [$t,$origin,$sales,$r,$router];
    }
}
