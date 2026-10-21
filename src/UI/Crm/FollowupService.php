<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskService;

/** Associations never imply a CRM stage/status transition. */
final readonly class FollowupService
{
    public function __construct(private Connection $db, private TaskService $tasks, private SubjectRegistry $subjects, private Clock $clock)
    {
    }

    private function subject(ExecutionContext $c, string $type, string $id, string $capability, bool $lock = false): void
    {
        if ($id === '' || strlen($id) > 120) {
            throw new DomainError('not_found');
        }$h = $this->subjects->handler($type);
        $q = $h->query($c, $capability)->where($h->scopeColumn(), $c->scope)->where($h->idColumn(), $id);
        if ($lock) {
            $q->lockForUpdate();
        }if (! $q->exists()) {
            throw new DomainError('not_found');
        }
    }

    public function create(ExecutionContext $c, string $type, string $subjectId, string $title, string $operation): array
    {
        $this->subject($c, $type, $subjectId, 'edit');

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'host_followup_create', 'type' => $type, 'subject' => $subjectId, 'title' => $title], function () use ($c, $type, $subjectId, $title, $operation) {
            $this->subject($c, $type, $subjectId, 'edit', true);
            $task = $this->tasks->create($c, $title, 'followup:'.hash('sha256', $operation));
            $this->db->table('tm_host_task_links')->insert(['scope_key' => $c->scope, 'task_id' => $task['id'], 'subject_type' => $type, 'subject_id' => $subjectId]);
            (new EventRecorder($this->db, $this->clock))->append($c, 'task', $task['public_key'], 'host_subject_linked', $operation, ['subject_type' => $type, 'subject_id' => $subjectId], $task['id']);

            return ['task_key' => $task['public_key'], 'subject_type' => $type, 'subject_id' => $subjectId];
        }, function ($r) use ($c) {
            $this->subject($c, $r['subject_type'], $r['subject_id'], 'view');
            $this->tasks->get($c, $r['task_key']);
        });
    }

    public function tasks(ExecutionContext $c, string $type, string $subjectId, int $afterId = 0, int $limit = 50): array
    {
        $this->subject($c, $type, $subjectId, 'view');
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new DomainError('pagination_invalid');
        }

        return $this->tasks->authorizedQuery($c)->whereNull('deleted_at')->whereIn('id', $this->db->table('tm_host_task_links')->where('scope_key', $c->scope)->where('subject_type', $type)->where('subject_id', $subjectId)->select('task_id'))->where('id','>',$afterId)->orderBy('id')->limit($limit)->get(['id', 'public_key', 'title', 'version'])->map(fn ($r) => (array) $r)->all();
    }
}
