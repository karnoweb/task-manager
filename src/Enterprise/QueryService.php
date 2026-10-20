<?php

namespace Karnoweb\TaskManager\Enterprise;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\AuthorizationRevision;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class QueryService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly AuthorizationRevision $revision, private readonly TaskService $tasks, private readonly CustomFieldService $fields) {}
    public function base(ExecutionContext $context, array $filters): Builder
    {
        if (array_diff(array_keys($filters), ['q','project_id','list_id','owner_ref','type_key','parent_task_id','priority','status_category','watcher_ref','assignee_ref','due_before','due_after','custom'])) { throw new DomainError('query_filter_invalid'); }
        $query = $this->tasks->authorizedQuery($context)->whereNull('deleted_at');
        foreach (['project_id','list_id','owner_ref','type_key','parent_task_id','priority'] as $key) { if (array_key_exists($key, $filters)) { if (!is_string($filters[$key]) && !is_int($filters[$key]) && $filters[$key] !== null) { throw new DomainError('query_filter_invalid'); } $query->where($key, $filters[$key]); } }
        if (isset($filters['q'])) { if (!is_string($filters['q']) || mb_strlen($filters['q']) > 500) { throw new DomainError('search_invalid'); } $term = str_replace(['!','%','_'], ['!!','!%','!_'], TextNormalizer::normalize($filters['q'])); $query->whereRaw("title_search_normalized LIKE ? ESCAPE '!'", ['%'.$term.'%']); }
        if (isset($filters['status_category'])) { if (!in_array($filters['status_category'], ['todo','in_progress','done','cancelled'], true)) { throw new DomainError('query_filter_invalid'); } $query->whereIn('id', StateIndex::currentTaskIds($this->db, $context->scope, [$filters['status_category']])); }
        if (isset($filters['watcher_ref'])) { $query->whereIn('id', $this->db->table('tm_task_watchers')->where('actor_ref', $filters['watcher_ref'])->select('task_id')); }
        if (isset($filters['assignee_ref'])) { $query->whereIn('id', $this->db->table('tm_workflow_instances as assigned')->where('assigned.scope_key', $context->scope)->where('assigned.slot_key','main')->where('assigned.primary_assignee',$filters['assignee_ref'])->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('tm_workflow_instances as newer')->whereColumn('newer.task_id','assigned.task_id')->where('newer.slot_key','main')->whereColumn('newer.id','>','assigned.id'))->select('assigned.task_id')); }
        foreach (['due_before' => '<', 'due_after' => '>='] as $key => $operator) { if (isset($filters[$key])) { if (!is_string($filters[$key])) { throw new DomainError('query_filter_invalid'); } try { $date = new \DateTimeImmutable($filters[$key], new \DateTimeZone('UTC')); } catch (\Exception) { throw new DomainError('date_invalid'); } $query->where('due_at', $operator, LocalTime::stamp($date)); } }
        $custom = $filters['custom'] ?? []; if (!is_array($custom) || !array_is_list($custom) || count($custom) > 20) { throw new DomainError('query_filter_invalid'); }
        foreach ($custom as $filter) { if (!is_array($filter) || !is_string($filter['field'] ?? null) || !is_string($filter['operator'] ?? null) || !array_key_exists('value',$filter) || array_diff(array_keys($filter), ['field','operator','value'])) { throw new DomainError('query_filter_invalid'); } $this->fields->filter($query, $context, $filter['field'], $filter['operator'], $filter['value']); }
        return $query;
    }
    private function canonical(array $value): array { if (!array_is_list($value)) { ksort($value); } foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = $this->canonical($item); } } return $value; }
    public function tasks(ExecutionContext $context, array $filters = [], string $sort = 'id', string $direction = 'asc', int $limit = 30, ?string $cursor = null): array
    {
        if (!in_array($direction, ['asc','desc'], true) || $limit < 1 || $limit > 100) { throw new DomainError('pagination_invalid'); }
        if (isset($filters['q']) && is_string($filters['q'])) { $filters['q'] = TextNormalizer::normalize($filters['q']); }
        $base = $this->base($context, $filters)->select('tm_tasks.*');
        if (str_starts_with($sort, 'custom:')) { [,,$subquery] = $this->fields->sortQuery($context, substr($sort, 7)); $base->addSelect(['sort_value' => $subquery]); }
        else { if (!in_array($sort, ['id','due_at','created_at','title_search_normalized','priority'], true)) { throw new DomainError('query_sort_invalid'); } $sort === 'priority' ? $base->selectRaw("CASE priority WHEN 'low' THEN 0 WHEN 'normal' THEN 1 WHEN 'high' THEN 2 WHEN 'urgent' THEN 3 ELSE 4 END as sort_value") : $base->addSelect($sort.' as sort_value'); }
        $hash = hash('sha256', json_encode($this->canonical([$filters,$sort,$direction,$limit]), JSON_THROW_ON_ERROR)); $permission = VisibilityEpoch::fingerprint($this->db, $context, $this->revision->revision($context));
        $query = $this->db->query()->fromSub($base, 'permitted_tasks'); $total = (clone $query)->count();
        if ($cursor !== null) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $cursor)) { throw new DomainError('cursor_invalid'); } $stored = $this->db->table('tm_query_cursors')->where('token_hash',hash('sha256',$cursor))->where('scope_key',$context->scope)->where('actor_ref',$context->actor)->where('expires_at','>',LocalTime::stamp($this->clock->now()))->first();
            if (!$stored || $stored->query_hash !== $hash || $stored->permission_hash !== $permission) { throw new DomainError('cursor_stale'); } $last = json_decode($stored->position,true,flags:JSON_THROW_ON_ERROR);
            if ($last['value'] === null) { $query->whereNull('sort_value')->where('id','>',$last['id']); }
            else { $query->where(fn ($q) => $q->where('sort_value',$direction === 'asc' ? '>' : '<',$last['value'])->orWhere(fn ($tie) => $tie->where('sort_value',$last['value'])->where('id','>',$last['id']))->orWhereNull('sort_value')); }
        }
        $rows = $query->orderByRaw('CASE WHEN sort_value IS NULL THEN 1 ELSE 0 END')->orderBy('sort_value',$direction)->orderBy('id')->limit($limit+1)->get(); $more = $rows->count() > $limit; $rows = $rows->take($limit); $token = null;
        if ($more) {
            $expired = $this->db->table('tm_query_cursors')->where('scope_key',$context->scope)->where('actor_ref',$context->actor)->where('expires_at','<=',LocalTime::stamp($this->clock->now()))->limit(1000)->pluck('token_hash'); if ($expired->isNotEmpty()) { $this->db->table('tm_query_cursors')->whereIn('token_hash',$expired->all())->delete(); }
            if ($this->db->table('tm_query_cursors')->where('scope_key',$context->scope)->where('actor_ref',$context->actor)->count()>=1000) { throw new DomainError('rate_limited'); }
            $last = $rows->last(); $token = bin2hex(random_bytes(32)); $this->db->table('tm_query_cursors')->insert(['token_hash'=>hash('sha256',$token),'scope_key'=>$context->scope,'actor_ref'=>$context->actor,'query_hash'=>$hash,'permission_hash'=>$permission,'position'=>json_encode(['value'=>$last->sort_value,'id'=>$last->id],JSON_THROW_ON_ERROR),'expires_at'=>LocalTime::stamp($this->clock->now()->modify('+15 minutes'))]);
        }
        return ['total'=>$total,'items'=>$rows->map(function ($row) { $item=(array)$row; unset($item['sort_value']); return $item; })->all(),'cursor'=>$token,'source'=>'authorized_sql_keyset'];
    }
    public function reindex(ExecutionContext $context, int $afterId = 0, int $limit = 1000): array
    {
        $context->require('migration.manage'); $this->authorization->authorize($context,'migration.manage'); if ($limit < 1 || $limit > 1000 || $afterId < 0) { throw new DomainError('pagination_invalid'); }
        $rows=$this->db->table('tm_tasks')->where('scope_key',$context->scope)->where('id','>',$afterId)->orderBy('id')->limit($limit)->get(['id','title']);
        foreach ($rows as $row) { $this->db->table('tm_tasks')->where('id',$row->id)->update(['title_search_normalized'=>TextNormalizer::normalize($row->title),'search_revision'=>TextNormalizer::VERSION]); } if ($rows->isNotEmpty()) { VisibilityEpoch::bump($this->db,$context->scope); } return ['updated'=>$rows->count(),'after_id'=>$rows->last()?->id];
    }
}
