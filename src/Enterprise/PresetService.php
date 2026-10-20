<?php

namespace Karnoweb\TaskManager\Enterprise;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Collaboration\ChecklistService;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskService;

final class PresetService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ChecklistService $checklists) {}
    private function authorize(ExecutionContext $context): void { $context->require('template.manage'); $this->authorization->authorize($context,'template.manage'); }
    public function create(ExecutionContext $context, string $name, array $definition, string $operation): array
    {
        $this->authorize($context); if (trim($name)==='' || mb_strlen($name)>255 || array_diff(array_keys($definition),['title','description','priority','checklists']) || !is_string($definition['title']??null) || trim($definition['title'])==='' || mb_strlen($definition['title'])>255 || (isset($definition['description']) && (!is_string($definition['description']) || mb_strlen($definition['description'])>16000)) || !in_array($definition['priority']??'normal',['low','normal','high','urgent'],true)) { throw new DomainError('preset_invalid'); }
        $lists=$definition['checklists']??[];if(!is_array($lists)||!array_is_list($lists)||count($lists)>20){throw new DomainError('preset_invalid');}$total=0;
        foreach($lists as $list){if(!is_array($list)||array_diff(array_keys($list),['title','items'])||!is_string($list['title']??null)||trim($list['title'])===''||mb_strlen($list['title'])>255||!is_array($list['items']??null)||!array_is_list($list['items'])||count($list['items'])>100){throw new DomainError('preset_invalid');}foreach($list['items'] as $item){if(!is_string($item)||trim($item)===''||mb_strlen($item)>255){throw new DomainError('preset_invalid');}++$total;}}
        if($total>200){throw new DomainError('preset_invalid');}
        return (new CommandExecutor($this->db))->execute($context,$operation,['action'=>'preset_create','name'=>$name,'definition'=>$definition],function()use($context,$name,$definition,$operation){$key=(string)Str::uuid();$this->db->table('tm_task_presets')->insert(['preset_key'=>$key,'scope_key'=>$context->scope,'owner_ref'=>$context->actor,'name'=>$name,'definition'=>json_encode($definition,JSON_THROW_ON_ERROR)]);(new EventRecorder($this->db,$this->clock))->append($context,'task_preset',$key,'preset_created',$operation,[]);return $this->get($context,$key);},fn($result)=>$this->get($context,$result['preset_key']));
    }
    public function get(ExecutionContext $context,string $key):array
    {
        $this->authorize($context);if(!Str::isUuid($key)){throw new DomainError('not_found');}$row=$this->db->table('tm_task_presets')->where('scope_key',$context->scope)->where('owner_ref',$context->actor)->where('preset_key',$key)->first();if(!$row){throw new DomainError('not_found');}return [...(array)$row,'definition'=>json_decode($row->definition,true,flags:JSON_THROW_ON_ERROR)];
    }
    public function instantiate(ExecutionContext $context,string $key,string $operation):array
    {
        $preset=$this->get($context,$key);
        return(new CommandExecutor($this->db))->execute($context,$operation,['action'=>'preset_instantiate','key'=>$key,'version'=>$preset['version']],function()use($context,$preset,$operation){$child=fn($suffix)=>'preset:'.hash('sha256',$operation.':'.$suffix);$d=$preset['definition'];$task=$this->tasks->create($context,$d['title'],$child('task'));$task=$this->tasks->mutate($context,$task['public_key'],$task['version'],$child('defaults'),'update',['description'=>$d['description']??null,'priority'=>$d['priority']??'normal']);foreach($d['checklists']??[] as $index=>$list){$created=$this->checklists->create($context,$task['public_key'],$list['title'],$child('list:'.$index));foreach($list['items'] as $itemIndex=>$item){$this->checklists->addItem($context,$created['public_key'],$itemIndex+1,$item,null,null,$child('item:'.$index.':'.$itemIndex));}}return $task;},function($result)use($context,$key){$this->get($context,$key);$this->tasks->get($context,$result['public_key']);});
    }
}
