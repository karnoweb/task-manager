<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class InstallationProbe extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app):array
    {
        $p=getenv('TM_PHASE6_INSTALL_PROFILE');$providers=[];
        if(in_array($p,['task-only','all'],true)){$providers[]=\Karnoweb\TaskManager\TaskManagerServiceProvider::class;}
        if(in_array($p,['kanban-only','calendar-only','all'],true)){$providers[]=\Livewire\LivewireServiceProvider::class;}
        if(in_array($p,['kanban-only','all'],true)){$providers[]=\Karnoweb\Kanban\KanbanServiceProvider::class;}
        if(in_array($p,['calendar-only','all'],true)){$providers[]=\Karnoweb\Calendar\CalendarServiceProvider::class;}
        if($p==='all'){$providers[]=\Karnoweb\Crm\CrmServiceProvider::class;}
        return $providers;
    }
    protected function defineEnvironment($app):void
    {
        $app['config']->set('database.default','testing');$app['config']->set('database.connections.testing',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$app['config']->set('app.key','base64:'.base64_encode(str_repeat('x',32)));
    }
    public function test_installed_profile_boots_real_packages_and_persists_only_its_own_truth():void
    {
        $p=getenv('TM_PHASE6_INSTALL_PROFILE');
        self::assertSame(in_array($p,['task-only','all'],true),class_exists(\Karnoweb\TaskManager\TaskService::class));
        self::assertSame(in_array($p,['kanban-only','all'],true),interface_exists(\Karnoweb\Kanban\Contracts\DataProvider::class));
        self::assertSame(in_array($p,['calendar-only','all'],true),interface_exists(\Karnoweb\Calendar\Contracts\EventProvider::class));
        if(in_array($p,['task-only','all'],true)){
            $this->artisan('migrate:fresh')->run();$c=new \Karnoweb\TaskManager\Context\ExecutionContext('1','1','host-install');$s=$this->app->make(\Karnoweb\TaskManager\TaskService::class);$t=$s->create($c,'Independent task','install-create');self::assertSame('Independent task',$s->get($c,$t['public_key'])['title']);$s->mutate($c,$t['public_key'],$t['version'],'install-done','complete');self::assertSame('done',$s->get($c,$t['public_key'])['state']);
            $c=new \Karnoweb\TaskManager\Context\ExecutionContext('1','1','host-project',capabilities:[...$c->capabilities,'workflow.manage','workflow.view','project.manage','project.view','report.view']);
            $manager=$this->app->make(\Karnoweb\TaskManager\TaskManager::class);$r=$manager->workflows()->publish($c,'independent',0,'host-workflow',['initial'=>'new','states'=>['new'=>['terminal'=>'none'],'done'=>['terminal'=>'success']],'transitions'=>['finish'=>['from'=>'new','to'=>'done']]]);$project=$manager->projects()->create($c,'HOST','Independent project',$r['id'],'host-project');self::assertSame('Independent project',$manager->projects()->get($c,$project['project_key'])['name']);self::assertSame(1,$manager->reports()->totals($c)['total_tasks']);self::assertInstanceOf(\Karnoweb\TaskManager\UI\IntegrationFactory::class,$manager->integrations());
        }
        Schema::create('probe_orders',function(Blueprint $t){$t->id();$t->string('title');$t->string('status');$t->string('scope_key');$t->string('owner_ref');$t->integer('version')->default(1);$t->timestamp('delivery_at');$t->timestamps();});
        \Illuminate\Support\Facades\DB::table('probe_orders')->insert(['title'=>'Host order','status'=>'new','scope_key'=>'1','owner_ref'=>'1','delivery_at'=>'2026-10-22 09:00:00']);
        if(in_array($p,['kanban-only','all'],true)){
            $this->app->make(\Karnoweb\Kanban\Models\KanbanModelRegistry::class)->register('orders',ProbeKanbanOrder::class);$this->app->make(\Karnoweb\Kanban\Support\BoardRegistry::class)->register('host.orders',ProbeBoardFactory::class);
            $ui=new \Karnoweb\Kanban\Data\BoardContext('host.orders','1','1','probe');$provider=$this->app->make(\Karnoweb\Kanban\Providers\EloquentKanbanDataProvider::class);$page=$provider->listCards($ui,new \Karnoweb\Kanban\Data\BoardQuery);self::assertCount(1,$page->items);
            $cmd=new \Karnoweb\Kanban\Data\ActionCommand('probe-move-1',\Karnoweb\Kanban\Enums\ActionType::MoveCard,['cardId'=>'orders:1','fromStageId'=>'new','toStageId'=>'sent'],'1');self::assertSame(\Karnoweb\Kanban\Enums\ActionStatus::SUCCESS,$this->app->make(\Karnoweb\Kanban\Actions\EloquentKanbanActionHandler::class)->execute($cmd,$ui)->status);
        }
        if(in_array($p,['calendar-only','all'],true)){
            $this->app->make(\Karnoweb\Calendar\Support\CalendarModelRegistry::class)->register('orders',ProbeCalendarOrder::class);$provider=$this->app->make(\Karnoweb\Calendar\Providers\EloquentEventProvider::class,['sourceKey'=>'orders']);$ui=new \Karnoweb\Calendar\Data\CalendarContext('host.calendar','1','1','en','UTC','probe');$q=new \Karnoweb\Calendar\Data\EventQuery(\Karnoweb\Calendar\Data\DateRange::ofLocalDays('2026-10-22','2026-10-23','UTC'));$page=$provider->fetch($q,$ui);self::assertCount(1,$page->events);self::assertTrue($page->events[0]->isReadOnly());
        }
        self::assertSame(1,\Illuminate\Support\Facades\DB::table('probe_orders')->count());
    }
}

if(interface_exists(\Karnoweb\Kanban\Contracts\DataProvider::class)){
    final class ProbeKanbanOrder extends \Illuminate\Database\Eloquent\Model{
        use \Karnoweb\Kanban\Concerns\HasKanbanCards;protected $table='probe_orders';protected $guarded=[];
        public static function kanbanDefinition():\Karnoweb\Kanban\Models\KanbanModelDefinition{return \Karnoweb\Kanban\Models\KanbanModelDefinition::make('orders',static::class)->fields(['stage'=>'status','version'=>'version'])->stages(['new'=>'New','sent'=>'Sent'])->transitions(['new'=>['sent']])->visibleQuery(fn($q,$c)=>$q->where('scope_key',$c->scopeKey)->where('owner_ref',$c->actorId))->authorize(fn($a,$r,$c)=>$r!==null&&(string)$r->scope_key===$c->scopeKey&&(string)$r->owner_ref===$c->actorId);}
    }
    final class ProbeBoardFactory implements \Karnoweb\Kanban\Contracts\BoardFactory{
        public function make(\Karnoweb\Kanban\Data\BoardContext $c):\Karnoweb\Kanban\Data\BoardDefinition{return (new \Karnoweb\Kanban\Support\BoardBuilder)->key($c->boardKey)->board(new \Karnoweb\Kanban\Data\BoardData($c->boardKey,'Orders',$c->scopeKey))->settings(['interaction'=>['transition_policy'=>'allow']])->sources(['orders'])->build();}
    }
}
if(interface_exists(\Karnoweb\Calendar\Contracts\EventProvider::class)){
    final class ProbeCalendarOrder extends \Illuminate\Database\Eloquent\Model{
        use \Karnoweb\Calendar\Concerns\HasCalendarEvents;protected $table='probe_orders';protected $guarded=[];
        public static function calendarDefinition():\Karnoweb\Calendar\Data\CalendarModelDefinition{return new \Karnoweb\Calendar\Data\CalendarModelDefinition(sourceKey:'orders',startField:'delivery_at',endField:null,scopeField:'scope_key',ownerField:'owner_ref',allDayField:null,descriptionField:null,locationField:null,categoryField:null,colorField:null,movableField:null,timezoneField:null,readOnly:true);}
    }
}
