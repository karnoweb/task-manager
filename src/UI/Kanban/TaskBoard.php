<?php

namespace Karnoweb\TaskManager\UI\Kanban;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\Kanban\Contracts\ActionHandler;
use Karnoweb\Kanban\Contracts\CardDetailsProvider;
use Karnoweb\Kanban\Contracts\DataProvider;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\ActionResult;
use Karnoweb\Kanban\Data\BoardContext;
use Karnoweb\Kanban\Data\BoardData;
use Karnoweb\Kanban\Data\BoardQuery;
use Karnoweb\Kanban\Data\CardData;
use Karnoweb\Kanban\Data\CardDetailAction;
use Karnoweb\Kanban\Data\CardDetailSectionData;
use Karnoweb\Kanban\Data\CountResult;
use Karnoweb\Kanban\Data\PageResult;
use Karnoweb\Kanban\Data\StageCount;
use Karnoweb\Kanban\Data\StageData;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Enterprise\TextNormalizer;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\UI\BoundCursor;
use Karnoweb\TaskManager\UI\CardIdentity;
use Karnoweb\TaskManager\UI\ContextBridge;
use Karnoweb\TaskManager\UI\Surface;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

/** Optional adapter: loaded only when the host selects this board and installs Kanban. */
final class TaskBoard implements ActionHandler, CardDetailsProvider, DataProvider
{
    public function __construct(private readonly Connection $db, private readonly TaskService $tasks,
        private readonly InstanceService $instances, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines,
        private readonly SubjectRegistry $subjects, private readonly AuthorizationProvider $authorization,
        private readonly Surface $surface, private readonly ContextBridge $bridge, private readonly BoundCursor $cursors,
        private readonly ?\Closure $handoff = null)
    {
    }

    private function context(BoardContext $c): ExecutionContext
    {
        return $this->bridge->resolve($c, $this->surface);
    }

    public function board(BoardContext $context): BoardData
    {
        $this->context($context);

        return new BoardData($context->boardKey, $context->boardKey, $context->scopeKey);
    }

    public function capabilities(BoardContext $context): array
    {
        $this->context($context);

        return ['read' => true, 'manualOrder' => false];
    }

    private function sources(BoardQuery $query): array
    {
        if (array_diff(array_keys($query->filters), ['sources']) !== [] || ! in_array($query->sort, ['manualOrder', 'id'], true) || $query->direction !== 'asc') {
            throw new DomainError('board_query_invalid');
        }
        $sources = $query->filters['sources'] ?? $this->surface->sources;
        if (! is_array($sources) || array_diff($sources, $this->surface->sources)) {
            throw new DomainError('not_found');
        }

        return array_values(array_unique($sources));
    }

    private function query(ExecutionContext $c, string $source, BoardQuery $query, bool $archived = false): Builder
    {
        $this->surface->allows($source);
        if ($source === 'tasks') {
            $q = $this->tasks->authorizedQuery($c)->whereNull('deleted_at');
            $archived ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
            $main = $this->db->table('tm_workflow_instances')->where('scope_key', $c->scope)->where('slot_key', 'main');
            $main->whereNotExists(function (Builder $newer) {
                $newer->selectRaw('1')->from('tm_workflow_instances as newer')->whereColumn('newer.task_id', 'tm_workflow_instances.task_id')->whereColumn('newer.scope_key', 'tm_workflow_instances.scope_key')->where('newer.slot_key', 'main')->whereColumn('newer.id', '>', 'tm_workflow_instances.id');
            });
            if (isset($this->surface->pipelines[$source])) {
                $pipeline = $this->pipelines->get($c, $this->surface->pipelines[$source]);
                $main->where('pipeline_id', $pipeline['id']);
            } else {
                $main->whereNull('pipeline_id')->where('workflow_key', 'personal');
            }
            if ($query->stageId !== null) {
                [$stageSource, $stage] = CardIdentity::parse($query->stageId, $this->surface);
                if ($source !== $stageSource) {
                    $main->whereRaw('1=0');
                } $main->where(isset($pipeline) ? 'active_stage_key' : 'current_state_key', $stage);
            }
            $q->whereIn('tm_tasks.id', $main->select('task_id'));
            if ($query->search !== null && $query->search !== '') {
                $q->where('title_search_normalized', 'like', '%'.TextNormalizer::normalize($query->search).'%');
            }

            return $q;
        }
        $q = $this->instances->authorizedQuery($c)->where('subject_type', $source);
        if ($archived) {
            $q->whereRaw('1=0');
        }
        $pipeline = $this->surface->pipelines[$source] ?? null;
        if ($pipeline === null) {
            throw new DomainError('surface_pipeline_required');
        }
        $p = $this->pipelines->get($c, $pipeline);
        $q->where('pipeline_id', $p['id']);
        if ($query->stageId !== null) {
            [$stageSource, $stage] = CardIdentity::parse($query->stageId, $this->surface);
            if ($source !== $stageSource) {
                $q->whereRaw('1=0');
            } $q->where('active_stage_key', $stage);
        }
        if ($query->search !== null && $query->search !== '') {
            $q->where('subject_id', 'like', '%'.$query->search.'%');
        }

        return $q;
    }

    public function stages(BoardContext $context, BoardQuery $query): array
    {
        $c = $this->context($context);
        $result = [];
        foreach ($this->sources($query) as $source) {
            if (isset($this->surface->pipelines[$source])) {
                $p = $this->pipelines->get($c, $this->surface->pipelines[$source]);
                $rows = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $p['id'])->orderBy('id')->get();
                foreach ($rows as $row) {
                    $result[] = new StageData(CardIdentity::make($source, $row->stage_key), $context->boardKey, $row->stage_key, count($result), wipLimit: $row->capacity);
                }
            } elseif ($source === 'tasks') {
                foreach (['pending', 'done'] as $state) {
                    $result[] = new StageData(CardIdentity::make($source, $state), $context->boardKey, $state, count($result));
                }
            } else {
                throw new DomainError('surface_pipeline_required');
            }
        }

        return $result;
    }

    private function project(BoardContext $context, ExecutionContext $c, string $source, array $row): CardData
    {
        if ($source === 'tasks') {
            $task = $this->tasks->get($c, $row['public_key']);
            $i = $this->db->table('tm_workflow_instances')->where('scope_key', $c->scope)->where('task_id', $row['id'])->where('slot_key', 'main')->orderByDesc('id')->first();
            $stage = $i->active_stage_key ?? $i->current_state_key;
            $can = $this->canEdit($c, $task['public_key']);

            return new CardData(CardIdentity::make($source, $task['public_key']), $context->boardKey, CardIdentity::make($source, $stage), $task['title'], description: $task['description'], priority: $task['priority'], dueAt: $task['due_at'], version: (string) $task['version'], archived: $task['archived_at'] !== null,
                metadata: ['task_key' => $task['public_key'], 'instance_key' => $i->instance_key], permissions: ['move' => $can, 'view' => true]);
        }
        $i = $this->instances->get($c, $row['instance_key']);

        return new CardData(CardIdentity::make($source, $i['instance_key']), $context->boardKey, CardIdentity::make($source, $i['active_stage_key']), $source.' '.$i['subject_id'], version: (string) $i['version'], permissions: ['move' => in_array('workflow.transition', $c->capabilities, true), 'view' => true]);
    }

    private function canEdit(ExecutionContext $c, string $key): bool
    {
        try {
            return $this->tasks->authorizedQuery($c, 'edit')->where('public_key', $key)->exists();
        } catch (DomainError) {
            return false;
        }
    }

    private function page(BoardContext $context, BoardQuery $query, bool $archived = false): PageResult
    {
        $c = $this->context($context);
        $sources = $this->sources($query);
        $binding = [$c->scope, $c->actor, $c->capabilities, $this->surface, $query->stageId, $query->search, $query->filters, $query->limit, $archived];
        $position = $this->cursors->decode($query->cursor, $binding);
        $index = $position['source'] ?? 0;
        $after = $position['id'] ?? 0;
        if (! is_int($index) || ! is_int($after) || $index < 0 || $after < 0) {
            throw new DomainError('cursor_invalid');
        }
        $items = [];
        $total = 0;
        foreach ($sources as $source) {
            $total += $this->query($c, $source, $query, $archived)->count();
        }
        for (; $index < count($sources); $index++, $after = 0) {
            $source = $sources[$index];
            $rows = $this->query($c, $source, $query, $archived)->where('id', '>', $after)->orderBy('id')->limit($query->limit - count($items) + 1)->get();
            foreach ($rows as $row) {
                if (count($items) === $query->limit) {
                    return new PageResult($items, $this->cursors->encode($binding, ['source' => $index, 'id' => $after]), true, $total);
                }
                $items[] = $this->project($context, $c, $source, (array) $row);
                $after = $row->id;
            }
        }

        return new PageResult($items, null, false, $total);
    }

    public function stageCards(BoardContext $context, string $stageId, BoardQuery $query): PageResult
    {
        return $this->page($context, new BoardQuery($query->view, $stageId, $query->cursor, $query->limit, $query->search, $query->filters, $query->sort, $query->direction));
    }

    public function listCards(BoardContext $context, BoardQuery $query): PageResult
    {
        return $this->page($context, $query);
    }

    public function archives(BoardContext $context, BoardQuery $query): PageResult
    {
        return $this->page($context, $query, true);
    }

    public function counts(BoardContext $context, BoardQuery $query): CountResult
    {
        $c = $this->context($context);
        $counts = [];
        $total = 0;
        foreach ($this->stages($context, $query) as $stage) {
            [$source] = CardIdentity::parse($stage->id, $this->surface);
            $q = new BoardQuery(stageId: $stage->id, search: $query->search, filters: $query->filters);
            $count = $this->query($c, $source, $q)->count();
            $counts[] = new StageCount($stage->id, $count, true);
            $total += $count;
        }

        return new CountResult($counts, $total);
    }

    public function card(BoardContext $context, string $cardId): ?CardData
    {
        $c = $this->context($context);
        [$source,$key] = CardIdentity::parse($cardId, $this->surface);
        try {
            $record = $source === 'tasks' ? $this->tasks->get($c, $key) : $this->instances->get($c, $key);
            $query = $this->query($c, $source, new BoardQuery, ($record['archived_at'] ?? null) !== null);
            if (! $query->where('id', $record['id'])->exists()) {
                return null;
            }

            return $this->project($context, $c, $source, $record);
        } catch (DomainError $e) {
            if ($e->getMessage() === 'not_found') {
                return null;
            } throw $e;
        }
    }

    public function execute(ActionCommand $command, BoardContext $context): ActionResult
    {
        try {
            $c = $this->context($context);
            $id = $command->payload['cardId'] ?? '';
            if (! is_string($id) || ! $this->card($context, $id)) {
                throw new DomainError('not_found');
            }
            $r = (new CommandExecutor($this->db))->execute($c, 'ui-board:'.hash('sha256', $command->operationId),
                ['surface' => $this->surface, 'type' => $command->type->value, 'payload' => $command->payload, 'version' => $command->expectedVersion],
                function () use ($command, $context, $id) {
                    $result = $this->domain($command, $context);
                    if ($result->status !== ActionStatus::SUCCESS) {
                        throw new DomainError($result->messageCode ?? 'ui_action_unsupported');
                    }

return ['card_id' => $id, 'version' => $result->newVersion, 'surface_hash' => hash('sha256', json_encode($this->surface, JSON_THROW_ON_ERROR))];
                },
                function ($r) use ($context) {
                    if (! $this->card($context, $r['card_id'])) {
                        throw new DomainError('not_found');
                    }
                });

            return ActionResult::success([$r['card_id']], ['board', 'calendar', 'drawer'], $r['version']);
        } catch (DomainError $e) {
            return $this->failure($e);
        }
    }

    private function domain(ActionCommand $command, BoardContext $context): ActionResult
    {
        try {
            $c = $this->context($context);
            $id = $command->payload['cardId'] ?? '';
            [$source,$key] = CardIdentity::parse($id, $this->surface);
            $card = $this->card($context, $id);
            if (! $card) {
                throw new DomainError('not_found');
            }
            if ($command->expectedVersion === null || ! ctype_digit($command->expectedVersion)) {
                throw new DomainError('expected_version_required');
            }
            if ($command->type !== ActionType::MoveCard && $command->type !== ActionType::ExecuteCardAction) {
                return ActionResult::unsupported();
            }
            $target = $command->payload['toStageId'] ?? null;
            if (! is_string($target)) {
                throw new DomainError('target_stage_required');
            }
            [$targetSource,$stage] = CardIdentity::parse($target, $this->surface);
            if ($targetSource !== $source) {
                throw new DomainError('cross_source_move_denied');
            }
            $version = (int) $command->expectedVersion;
            $route = $this->surface->handoffRoutes[$target] ?? null;
            if ($route !== null) {
                if ($this->handoff === null) {
                    throw new DomainError('handoff_handler_required');
                }
                $result = ($this->handoff)($c, $source, $key, $version, $command->operationId, $route, $command->payload);
            } elseif ($source === 'tasks' && ! isset($this->surface->pipelines[$source])) {
                $action = match ($stage) {
                    'done' => 'complete', 'pending' => 'reopen', default => throw new DomainError('stage_mapping_invalid')
                };
                $result = $this->tasks->mutate($c, $key, $version, $command->operationId, $action);
            } else {
                $instance = $source === 'tasks' ? (array) $this->db->table('tm_workflow_instances')->where('scope_key', $c->scope)->where('task_id', $this->tasks->get($c, $key)['id'])->where('slot_key', 'main')->orderByDesc('id')->first() : $this->instances->get($c, $key);
                if ($source === 'tasks') {
                    $locked = $this->tasks->authorizedQuery($c, 'edit')->where('public_key', $key)->lockForUpdate()->first();
                    if (! $locked) {
                        throw new DomainError('not_found');
                    }if ($locked->version !== $version) {
                        throw new DomainError('version_conflict');
                    }
                }
                $definition = $this->workflows->revision($c, $instance['workflow_revision_id'])['definition'];
                $targetState = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $instance['pipeline_id'])->where('stage_key', $stage)->value('state_key');
                $edges = array_filter($definition['transitions'], fn ($e) => $e['from'] === $instance['current_state_key'] && $e['to'] === $targetState);
                $edge = $command->payload['transition'] ?? (count($edges) === 1 ? array_key_first($edges) : null);
                if (! is_string($edge) || ! isset($edges[$edge])) {
                    throw new DomainError('transition_ambiguous');
                }
                $result = $this->instances->transition($c, $instance['instance_key'], $source === 'tasks' ? $instance['version'] : $version, $command->operationId, $edge, $command->payload['formData'] ?? $command->payload['fields'] ?? [], $stage);
            }

            return ActionResult::success([$id], ['board', 'calendar', 'drawer'], (string) ($result['version'] ?? $version));
        } catch (DomainError $e) {
            return $this->failure($e);
        }
    }

    private function failure(DomainError $error): ActionResult
    {
        $reason = preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'domain_rejected';
        $status = match ($reason) {
            'not_found' => ActionStatus::NOT_FOUND, 'capability_denied','access_denied' => ActionStatus::FORBIDDEN, 'version_conflict','idempotency_key_conflict' => ActionStatus::CONFLICT, 'capacity_exceeded' => ActionStatus::WIP_LIMIT, default => ActionStatus::DOMAIN_REJECTED
        };

        return new ActionResult($status, messageCode: $reason);
    }

    public function operationStatus(BoardContext $context, string $operationId): ?ActionResult
    {
        $c = $this->context($context);
        $row = $this->db->table('tm_command_journal')->where('scope_key', $c->scope)->where('actor_ref', $c->actor)->where('operation_id', 'ui-board:'.hash('sha256', $operationId))->first();
        if (! $row || $row->status !== 'completed') {
            return null;
        }
        $r = json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
        if (($r['surface_hash'] ?? null) !== hash('sha256', json_encode($this->surface, JSON_THROW_ON_ERROR))) {
            return null;
        }
        if (! $this->card($context, $r['card_id'])) {
            return null;
        }

        return ActionResult::success([$r['card_id']], ['board', 'calendar'], (string) ($r['version'] ?? ''));
    }

    public function sections(BoardContext $context, CardData $card): array
    {
        if (! $this->card($context, $card->id)) {
            throw new DomainError('not_found');
        }
        $c = $this->context($context);
        $sections = [];
        foreach (['activity' => 'activity.view', 'sla' => 'workflow.view', 'handoffs' => 'handoff.view'] as $key => $capability) {
            $allowed = in_array($capability, $c->capabilities, true);
            if ($allowed) {
                try {
                    $this->authorization->authorize($c, $capability);
                } catch (DomainError) {
                    $allowed = false;
                }
            }$sections[] = new CardDetailSectionData($key, ucfirst($key), capabilities: ['canView' => $allowed]);
        }

        return $sections;
    }

    public function loadSection(BoardContext $context, CardData $card, string $key, ?string $cursor, int $limit): CardDetailSectionData
    {
        $c = $this->context($context);
        $fresh = $this->card($context, $card->id);
        if (! $fresh) {
            throw new DomainError('not_found');
        }
        if (! in_array($key, ['activity', 'sla', 'handoffs'], true) || $limit < 1 || $limit > 50) {
            throw new DomainError('section_invalid');
        }
        $capability = match ($key) {
            'activity' => 'activity.view','handoffs' => 'handoff.view',default => 'workflow.view'
        };
        $c->require($capability);
        $this->authorization->authorize($c, $capability);
        [$source,$recordKey] = CardIdentity::parse($card->id, $this->surface);
        $instance = $source === 'tasks' ? $this->instances->get($c, $fresh->metadata['instance_key']) : $this->instances->get($c, $recordKey);
        $binding = [$c->scope, $c->actor, $this->surface, $card->id, $key, $limit];
        $after = $this->cursors->decode($cursor, $binding)['id'] ?? 0;
        if (! is_int($after) || $after < 0) {
            throw new DomainError('cursor_invalid');
        }
        $items = [];
        $last = null;
        if ($key === 'activity') {
            $c->require('activity.view');
            $this->authorization->authorize($c, 'activity.view');
            $q = $this->db->table('tm_instance_transitions')->where('scope_key', $c->scope)->where('instance_id', $instance['id']);
            $rows = $q->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
            foreach ($rows->take($limit) as $r) {
                $items[] = ['id' => (string) $r->id, 'label' => $r->from_state.' â†’ '.$r->to_state, 'value' => $r->occurred_at];
                $last = $r->id;
            }
        } elseif ($key === 'sla') {
            $rows = $this->db->table('tm_stage_visits')->where('scope_key', $c->scope)->where('instance_id', $instance['id'])->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
            foreach ($rows->take($limit) as $r) {
                $items[] = ['id' => (string) $r->id, 'label' => $r->stage_key, 'value' => $r->entered_at, 'status' => $r->exited_at === null ? 'active' : 'closed'];
                $last = $r->id;
            }
        } else {
            // Both sides must be currently readable; private host payloads never enter the drawer.
            $rows = $this->db->table('tm_handoffs')->where('scope_key', $c->scope)->where('origin_id', $instance['id'])->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
            foreach ($rows->take($limit) as $r) {
                try {
                    $target = $this->db->table('tm_workflow_instances')->where('id', $r->destination_id)->value('instance_key');
                    $this->instances->get($c, $target);
                    $items[] = ['id' => (string) $r->id, 'label' => 'Handoff', 'status' => $r->status];
                } catch (DomainError) {
                } $last = $r->id;
            }
        }
        $next = $rows->count() > $limit && $last !== null ? $this->cursors->encode($binding, ['id' => $last]) : null;

        return new CardDetailSectionData($key, ucfirst($key), state: $items ? 'ready' : 'empty', items: $items, nextCursor: $next, capabilities: ['canView' => true]);
    }

    public function performAction(BoardContext $context, CardData $card, CardDetailAction $action): ActionResult
    {
        if (! $this->card($context,$card->id)) {
            return new ActionResult(ActionStatus::NOT_FOUND);
        }

        return ActionResult::unsupported(); // These descriptors intentionally advertise read-only sections.
    }
}
