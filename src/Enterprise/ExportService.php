<?php

namespace Karnoweb\TaskManager\Enterprise;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\AuthorizationRevision;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\ExportStorage;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\InstanceService;

final class ExportService
{
    public function __construct(private readonly Connection $db,private readonly Clock $clock,private readonly AuthorizationProvider $authorization,private readonly AuthorizationRevision $revision,private readonly QueryService $queries,private readonly TaskService $tasks,private readonly InstanceService $instances,private readonly GroupService $groups,private readonly ExportStorage $storage){}
    private function authorize(ExecutionContext $context):void{$context->require('export.create');$this->authorization->authorize($context,'export.create');}
    private function definition(ExecutionContext $context,array $definition):array
    {
        if(array_diff(array_keys($definition),['source','format','filters','group_key','columns'])||!in_array($definition['source']??'tasks',['tasks','group_closure'],true)||!in_array($definition['format']??'csv',['csv','json'],true)){throw new DomainError('export_invalid');}
        $source=$definition['source']??'tasks';$filters=$definition['filters']??[];if(!is_array($filters)){throw new DomainError('export_invalid');}
        $allowed=$source==='tasks'?['public_key','display_key','title','priority','due_at','starts_at','ends_at','created_at','type_key']:['instance_key','subject_type','subject_id','result','reason_code'];$columns=$definition['columns']??$allowed;
        if(!is_array($columns)||!array_is_list($columns)||!$columns||array_diff($columns,$allowed)||count(array_unique($columns))!==count($columns)){throw new DomainError('export_columns_invalid');}
        if($source==='tasks'){$this->queries->base($context,$filters);}else{if(!is_string($definition['group_key']??null)||$filters){throw new DomainError('export_invalid');}$this->groups->get($context,$definition['group_key']);}
        return ['source'=>$source,'format'=>$definition['format']??'csv','filters'=>$filters,'group_key'=>$definition['group_key']??null,'columns'=>$columns];
    }
    public function enqueue(ExecutionContext $context,array $input,string $operation,int $lifetimeSeconds=3600):array
    {
        $this->authorize($context);$definition=$this->definition($context,$input);if($lifetimeSeconds<60||$lifetimeSeconds>86400){throw new DomainError('export_expiry_invalid');}
        return(new CommandExecutor($this->db))->execute($context,$operation,['action'=>'export_enqueue','definition'=>$definition,'lifetime'=>$lifetimeSeconds],function()use($context,$definition,$operation,$lifetimeSeconds){$key=(string)Str::uuid();$cutoff=(int)($this->permitted($context,$definition,0,PHP_INT_MAX)->max($definition['source']==='tasks'?'id':'item.id')??0);$this->db->table('tm_exports')->insert(['export_key'=>$key,'scope_key'=>$context->scope,'actor_ref'=>$context->actor,'definition'=>json_encode($definition,JSON_THROW_ON_ERROR),'permission_hash'=>$this->permission($context),'cutoff_id'=>$cutoff,'expires_at'=>LocalTime::stamp($this->clock->now()->modify('+'.$lifetimeSeconds.' seconds'))]);(new EventRecorder($this->db,$this->clock))->append($context,'export',$key,'export_enqueued',$operation,['format'=>$definition['format']]);return $this->get($context,$key);},fn($result)=>$this->get($context,$result['export_key']));
    }
    private function permission(ExecutionContext $context):string{return VisibilityEpoch::fingerprint($this->db,$context,$this->revision->revision($context));}
    public function get(ExecutionContext $context,string $key):array
    {
        $this->authorize($context);if(!Str::isUuid($key)){throw new DomainError('not_found');}$row=$this->db->table('tm_exports')->where('scope_key',$context->scope)->where('actor_ref',$context->actor)->where('export_key',$key)->first();if(!$row){throw new DomainError('not_found');}$result=(array)$row;$result['definition']=json_decode($row->definition,true,flags:JSON_THROW_ON_ERROR);unset($result['file_ref'],$result['lease_token'],$result['permission_hash']);return $result;
    }
    private function permitted(ExecutionContext $context,array $definition,int $after,int $cutoff):\Illuminate\Database\Query\Builder
    {
        if($definition['source']==='tasks'){return $this->queries->base($context,$definition['filters'])->where('id','>',$after)->where('id','<=',$cutoff)->orderBy('id');}
        $group=$this->groups->get($context,$definition['group_key']);
        return $this->db->table('tm_group_operation_items as item')->whereIn('item.run_id',$this->db->table('tm_group_operations')->where('group_id',$group['id'])->where('scope_key',$context->scope)->select('id'))
            ->whereIn('item.instance_id',$this->instances->authorizedQuery($context)->select('id'))->join('tm_workflow_instances as exported_instance','exported_instance.id','=','item.instance_id')->where('item.id','>',$after)->where('item.id','<=',$cutoff)
            ->orderBy('item.id')->select(['item.id','item.instance_id','exported_instance.instance_key','exported_instance.subject_type','exported_instance.subject_id','item.result','item.reason_code']);
    }
    /** The stream reauthorizes each bounded SQL batch; earlier bytes cannot be recalled. */
    public function stream(ExecutionContext $context,array $input):\Generator
    {
        $this->authorize($context);$definition=$this->definition($context,$input);$cutoff=(int)($this->permitted($context,$definition,0,PHP_INT_MAX)->max($definition['source']==='tasks'?'id':'item.id')??0);yield from $this->chunks($context,$definition,$cutoff,$this->permission($context));
    }
    private function csv(array $cells):string
    {
        $stream=fopen('php://temp/maxmemory:65536','w+b');foreach($cells as &$cell){$cell=(string)($cell??'');if(preg_match('/^[\s\x{FEFF}]*(?:[=+@\-]|\t|\r)/u',$cell)||str_starts_with($cell,"\t")||str_starts_with($cell,"\r")){$cell="'".$cell;}}unset($cell);fputcsv($stream,$cells,',','"','',"\r\n");rewind($stream);$bytes=stream_get_contents($stream);fclose($stream);return $bytes;
    }
    private function chunks(ExecutionContext $context,array $definition,int $cutoff,string $permission,?callable $remember=null):\Generator
    {
        if($definition['format']==='csv'){yield $this->csv($definition['columns']);}else{yield '[';}$after=0;$first=true;
        while(true){$this->authorize($context);if($this->permission($context)!==$permission){throw new DomainError('export_permission_revoked');}$rows=$this->permitted($context,$definition,$after,$cutoff)->limit(100)->get();if($rows->isEmpty()){break;}if($remember!==null){$remember($rows);}
            foreach($rows as $row){$data=[];foreach($definition['columns'] as $column){$data[$column]=$row->{$column}??null;}yield $definition['format']==='csv'?$this->csv(array_values($data)):($first?'':',').json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$first=false;}$after=$rows->last()->id;
        }
        if($definition['format']==='json'){yield ']';}
    }
    public function build(ExecutionContext $context,string $key,int $version):array
    {
        $this->authorize($context);if(!$context->serviceActor||$this->db->transactionLevel()!==0){throw new DomainError('export_worker_context_invalid');}$token=(string)Str::uuid();
        $job=$this->db->transaction(function()use($context,$key,$version,$token){$this->get($context,$key);$row=$this->db->table('tm_exports')->where('scope_key',$context->scope)->where('export_key',$key)->lockForUpdate()->first();if($row->state==='ready'){$this->checkDownloadPermission($context,$row);return (array)$row;}if($row->version!==$version){throw new DomainError('version_conflict');}if(!in_array($row->state,['pending','building'],true)||($row->lease_until!==null&&$row->lease_until>LocalTime::stamp($this->clock->now()))){throw new DomainError('export_not_claimable');}
            if($row->expires_at<=LocalTime::stamp($this->clock->now())||$row->permission_hash!==$this->permission($context)){throw new DomainError('export_permission_revoked');}$this->definition($context,json_decode($row->definition,true,flags:JSON_THROW_ON_ERROR));$this->db->table('tm_exports')->where('id',$row->id)->update(['state'=>'building','lease_token'=>$token,'lease_until'=>LocalTime::stamp($this->clock->now()->modify('+10 minutes')),'attempts'=>$row->attempts+1]);$this->db->table('tm_export_items')->where('export_id',$row->id)->delete();return (array)$row;},3);
        if($job['state']==='ready'){return $this->get($context,$key);}$definition=json_decode($job['definition'],true,flags:JSON_THROW_ON_ERROR);$reference=null;
        try{$reference=$this->storage->store($context,$this->chunks($context,$definition,$job['cutoff_id'],$job['permission_hash'],function($rows)use($job,$definition,$token){$current=$this->db->table('tm_exports')->where('id',$job['id'])->where('state','building')->where('lease_token',$token)->where('lease_until','>',LocalTime::stamp($this->clock->now()))->exists();if(!$current){throw new DomainError('export_lease_lost');}$items=[];foreach($rows as $row){$items[]=['export_id'=>$job['id'],'resource_type'=>$definition['source']==='tasks'?'task':'instance','task_id'=>$definition['source']==='tasks'?$row->id:null,'instance_id'=>$definition['source']==='tasks'?null:$row->instance_id];}$this->db->table('tm_export_items')->insertOrIgnore($items);}));
            $this->db->transaction(function()use($context,$job,$token,$reference,$key){$row=$this->db->table('tm_exports')->where('id',$job['id'])->lockForUpdate()->first();if($row->lease_token!==$token||$row->state!=='building'||$row->lease_until<=LocalTime::stamp($this->clock->now())){throw new DomainError('export_lease_lost');}$this->checkDownloadPermission($context,$row);$this->db->table('tm_exports')->where('id',$row->id)->update(['state'=>'ready','file_ref'=>$reference,'version'=>$row->version+1,'lease_token'=>null,'lease_until'=>null]);(new EventRecorder($this->db,$this->clock))->append($context,'export',$key,'export_ready','export-ready:'.$row->id.':'.$row->attempts,[]);},3);
        }catch(\Throwable $error){if($reference!==null){$this->storage->delete($context,$reference);}$reason=$error instanceof DomainError&&preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'export_build_failed';$this->db->table('tm_exports')->where('id',$job['id'])->where('lease_token',$token)->update(['state'=>$reason==='export_permission_revoked'?'revoked':'failed','reason_code'=>$reason,'version'=>$version+1,'lease_token'=>null,'lease_until'=>null]);throw $error;}return $this->get($context,$key);
    }
    private function checkDownloadPermission(ExecutionContext $context,object $row):void
    {
        if($row->permission_hash!==$this->permission($context)||$row->expires_at<=LocalTime::stamp($this->clock->now())){throw new DomainError('export_permission_revoked');}
        try{$this->definition($context,json_decode($row->definition,true,flags:JSON_THROW_ON_ERROR));$items=$this->db->table('tm_export_items')->where('export_id',$row->id);$tasks=(clone$items)->where('resource_type','task');$instances=(clone$items)->where('resource_type','instance');if((clone$tasks)->whereNotIn('task_id',$this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id'))->exists()||(clone$instances)->whereNotIn('instance_id',$this->instances->authorizedQuery($context)->select('id'))->exists()){throw new DomainError('export_permission_revoked');}}
        catch(DomainError){throw new DomainError('export_permission_revoked');}
    }
    public function download(ExecutionContext $context,string $key):mixed
    {
        $this->get($context,$key);$row=$this->db->table('tm_exports')->where('scope_key',$context->scope)->where('actor_ref',$context->actor)->where('export_key',$key)->first();if($row->state!=='ready'||$row->file_ref===null){throw new DomainError('export_not_ready');}$this->checkDownloadPermission($context,$row);return $this->storage->read($context,$row->file_ref);
    }
}
