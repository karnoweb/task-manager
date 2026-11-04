<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskService;

/**
 * Real tasks associated with a registered host subject (lead, deal, ticket...).
 * An association never implies a state transition of the subject.
 */
final readonly class SubjectTaskService
{
    public function __construct(private Connection $db, private TaskService $tasks, private SubjectRegistry $subjects, private Clock $clock)
    {
        Tables::assertConnection($db);
    }

    private function subject(ExecutionContext $c, string $type, string $id, string $capability, bool $lock = false): void
    {
        if ($id === '' || strlen($id) > 120) {
            throw new DomainError('not_found');
        }
        $h = $this->subjects->handler($type);
        $q = $h->query($c, $capability)->where($h->scopeColumn(), SubjectScope::value($h, $c))->where($h->idColumn(), $id);
        if ($lock) {
            $q->lockForUpdate();
        }
        if (! $q->exists()) {
            throw new DomainError('not_found');
        }
    }

    public function create(ExecutionContext $c, string $type, string $subjectId, string $title, string $operation): array
    {
        $c->validateTenant();
        $this->subject($c, $type, $subjectId, 'edit');

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'host_followup_create', 'type' => $type, 'subject' => $subjectId, 'title' => $title], function () use ($c, $type, $subjectId, $title, $operation) {
            $this->subject($c, $type, $subjectId, 'edit', true);
            $task = $this->tasks->create($c, $title, 'followup:'.hash('sha256', $operation));
            $this->db->table(Tables::name('host_task_links'))->insert(['scope_key' => $c->scope, 'task_id' => $task['id'], 'subject_type' => $type, 'subject_id' => $subjectId]);
            (new EventRecorder($this->db, $this->clock))->append($c, 'task', $task['public_key'], 'host_subject_linked', $operation, ['subject_type' => $type, 'subject_id' => $subjectId], $task['id']);

            return ['task_key' => $task['public_key'], 'subject_type' => $type, 'subject_id' => $subjectId];
        }, function ($r) use ($c) {
            $this->subject($c, $r['subject_type'], $r['subject_id'], 'view');
            $this->tasks->get($c, $r['task_key']);
        });
    }

    /**
     * Associates an existing task the actor may edit with a registered subject of the same scope.
     * Idempotent per operation id and per (task, subject) pair; it never changes the task or the subject.
     *
     * @return array{task_key:string,subject_type:string,subject_id:string}
     */
    public function link(ExecutionContext $c, string $type, string $subjectId, string $taskKey, string $operation): array
    {
        $c->validateTenant();
        $this->subject($c, $type, $subjectId, 'edit');
        if (! Str::isUuid($taskKey)) {
            throw new DomainError('not_found');
        }

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'host_subject_link', 'type' => $type, 'subject' => $subjectId, 'task' => $taskKey], function () use ($c, $type, $subjectId, $taskKey, $operation) {
            $this->subject($c, $type, $subjectId, 'edit', true);
            $task = $this->tasks->authorizedQuery($c, 'edit')->whereNull('deleted_at')->where('public_key', $taskKey)->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            }
            $created = $this->db->table(Tables::name('host_task_links'))->insertOrIgnore(['scope_key' => $c->scope, 'task_id' => $task->id, 'subject_type' => $type, 'subject_id' => $subjectId]);
            if ($created > 0) {
                (new EventRecorder($this->db, $this->clock))->append($c, 'task', $task->public_key, 'host_subject_linked', $operation, ['subject_type' => $type, 'subject_id' => $subjectId], $task->id);
            }

            return ['task_key' => $task->public_key, 'subject_type' => $type, 'subject_id' => $subjectId];
        }, function ($r) use ($c) {
            $this->subject($c, $r['subject_type'], $r['subject_id'], 'view');
            $this->tasks->get($c, $r['task_key']);
        });
    }

    /**
     * Re-points every task association of one subject to another subject of the same scope (for example when
     * two host records are merged). The source subject is not read, so it may already be merged or archived;
     * the destination must be editable. All associated tasks must be editable by the actor, otherwise nothing moves.
     * A task already associated with the destination keeps a single association.
     *
     * @return array{moved:int,from_type:string,from_id:string,to_type:string,to_id:string}
     */
    public function move(ExecutionContext $c, string $fromType, string $fromId, string $toType, string $toId, string $operation): array
    {
        $c->validateTenant();
        foreach ([$fromType, $fromId] as $value) {
            if (trim($value) === '' || strlen($value) > 120) {
                throw new DomainError('not_found');
            }
        }
        if ($fromType === $toType && $fromId === $toId) {
            throw new DomainError('subject_move_invalid');
        }
        $this->subject($c, $toType, $toId, 'edit');

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'host_subject_move', 'from_type' => $fromType, 'from' => $fromId, 'to_type' => $toType, 'to' => $toId], function () use ($c, $fromType, $fromId, $toType, $toId, $operation) {
            $this->subject($c, $toType, $toId, 'edit', true);
            $links = $this->db->table(Tables::name('host_task_links'))->where('scope_key', $c->scope)->where('subject_type', $fromType)->where('subject_id', $fromId)->orderBy('id')->lockForUpdate()->get();
            $taskIds = $links->pluck('task_id')->all();
            if ($taskIds !== [] && $this->tasks->authorizedQuery($c, 'edit')->whereIn('id', $taskIds)->count() !== count($taskIds)) {
                throw new DomainError('access_denied');
            }
            $recorder = new EventRecorder($this->db, $this->clock);
            foreach ($links as $link) {
                $exists = $this->db->table(Tables::name('host_task_links'))->where('task_id', $link->task_id)->where('subject_type', $toType)->where('subject_id', $toId)->exists();
                $query = $this->db->table(Tables::name('host_task_links'))->where('id', $link->id);
                $exists ? $query->delete() : $query->update(['subject_type' => $toType, 'subject_id' => $toId]);
                $key = $this->db->table(Tables::name('tasks'))->where('id', $link->task_id)->value('public_key');
                $recorder->append($c, 'task', $key, 'host_subject_moved', $operation, ['from_type' => $fromType, 'from_id' => $fromId, 'subject_type' => $toType, 'subject_id' => $toId], $link->task_id);
            }

            return ['moved' => $links->count(), 'from_type' => $fromType, 'from_id' => $fromId, 'to_type' => $toType, 'to_id' => $toId];
        }, function ($r) use ($c) {
            $this->subject($c, $r['to_type'], $r['to_id'], 'view');
        });
    }

    /** @return list<array{id:int,public_key:string,title:string,version:int}> */
    public function tasks(ExecutionContext $c, string $type, string $subjectId, int $afterId = 0, int $limit = 50): array
    {
        $c->validateTenant();
        $this->subject($c, $type, $subjectId, 'view');
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new DomainError('pagination_invalid');
        }

        return $this->tasks->authorizedQuery($c)->whereNull('deleted_at')->whereIn('id', $this->db->table(Tables::name('host_task_links'))->where('scope_key', $c->scope)->where('subject_type', $type)->where('subject_id', $subjectId)->select('task_id'))->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get(['id', 'public_key', 'title', 'version'])->map(fn ($r) => (array) $r)->all();
    }

    /** The subject a visible task is associated with, or null when it has none. */
    public function subjectOf(ExecutionContext $c, string $taskKey): ?array
    {
        $c->validateTenant();
        $task = $this->tasks->get($c, $taskKey);
        $link = $this->db->table(Tables::name('host_task_links'))->where('scope_key', $c->scope)->where('task_id', $task['id'])->orderBy('id')->first();

        return $link ? ['subject_type' => $link->subject_type, 'subject_id' => $link->subject_id] : null;
    }
}
