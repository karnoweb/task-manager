<?php

namespace Karnoweb\TaskManager\Collaboration;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskResourceService;
use Karnoweb\TaskManager\TaskService;

final class CollaborationService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly TaskResourceService $resources) {}
    private function authorize(ExecutionContext $context, string $capability): void { $context->require($capability); $this->authorization->authorize($context, $capability); }
    public function comment(ExecutionContext $context, string $taskKey, string $body, array $mentions, ?string $parentKey, string $operation): array
    {
        $this->authorize($context, 'comment.create');
        if (!array_is_list($mentions) || count($mentions) > 50) { throw new DomainError('mentions_invalid'); }
        foreach ($mentions as $actor) { if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 120) { throw new DomainError('mentions_invalid'); } }
        $mentions = array_values(array_unique($mentions)); sort($mentions);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'collaboration_comment', 'task' => $taskKey, 'body' => $body, 'mentions' => $mentions, 'parent' => $parentKey], function () use ($context, $taskKey, $body, $mentions, $parentKey, $operation) {
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->whereNull('deleted_at')->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); }
            $parent = $parentKey === null ? null : $this->resources->get($context, 'comment', $parentKey);
            if ($parent !== null && ($parent['task_id'] !== $task->id || $parent['deleted_at'] !== null)) { throw new DomainError('not_found'); }
            $comment = $this->resources->create($context, $taskKey, 'comment', ['body' => $body], 'comment-content:'.hash('sha256', $operation));
            if ($parent !== null) { $this->db->table('tm_comments')->where('id', $comment['id'])->update(['parent_comment_id' => $parent['id']]); }
            foreach ($mentions as $actor) {
                if (!RecipientAccess::permitted($this->tasks, $context, $task->id, $actor)) { continue; }
                $this->db->table('tm_comment_mentions')->insert(['comment_id' => $comment['id'], 'actor_ref' => $actor]);
                (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'comment_mentioned', $operation, ['comment_key' => $comment['public_key'], 'recipient_ref' => $actor], $task->id);
            }
            return $this->resources->get($context, 'comment', $comment['public_key']);
        }, fn ($result) => $this->resources->get($context, 'comment', $result['public_key']));
    }
    public function editComment(ExecutionContext $context, string $key, int $version, ?string $body, string $operation): array
    {
        $this->authorize($context, 'comment.edit_own');
        return $this->resources->change($context, 'comment', $key, $version, $operation, $body === null ? null : ['body' => $body]);
    }
    public function history(ExecutionContext $context, string $key, int $afterVersion = 0, int $limit = 100): array
    {
        $comment = $this->resources->get($context, 'comment', $key);
        if ($context->actor !== $comment['author_ref']) { $this->authorize($context, 'comment.moderate'); }
        if ($afterVersion < 0 || $limit < 1 || $limit > 100) { throw new DomainError('pagination_invalid'); }
        return $this->db->table('tm_comment_revisions')->where('comment_id', $comment['id'])->where('version', '>', $afterVersion)->orderBy('version')->limit($limit)->get()->map(function ($row) {
            return [...(array) $row, 'body_html' => htmlspecialchars($row->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
        })->all();
    }
    public function watch(ExecutionContext $context, string $taskKey, int $version, string $actor, bool $enabled, string $operation): array
    {
        $this->authorize($context, 'watcher.manage');
        if (trim($actor) === '' || strlen($actor) > 120) { throw new DomainError('watcher_invalid'); }
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'task_watch', 'task' => $taskKey, 'version' => $version, 'actor' => $actor, 'enabled' => $enabled], function () use ($context, $taskKey, $version, $actor, $enabled, $operation) {
            $task = $this->tasks->authorizedQuery($context, $actor === $context->actor ? 'view' : 'edit')->where('public_key', $taskKey)->whereNull('deleted_at')->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); }
            if ($task->version !== $version) { throw new DomainError('version_conflict'); }
            if ($enabled && !RecipientAccess::permitted($this->tasks, $context, $task->id, $actor)) { throw new DomainError('watcher_ineligible'); }
            $this->db->table('tm_task_watchers')->where('task_id', $task->id)->where('actor_ref', $actor)->delete();
            if ($enabled) { $this->db->table('tm_task_watchers')->insert(['task_id' => $task->id, 'actor_ref' => $actor, 'created_at' => $this->now()]); }
            $this->db->table('tm_tasks')->where('id', $task->id)->increment('version');
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'watcher_changed', $operation, ['actor_ref' => $actor, 'enabled' => $enabled], $task->id);
            return $this->tasks->get($context, $taskKey);
        }, fn ($result) => $this->tasks->get($context, $result['public_key']));
    }
    public function notificationRecipients(ExecutionContext $context, string $taskKey, ?string $commentKey = null): array
    {
        $this->authorize($context, 'watcher.manage'); $task = $this->tasks->get($context, $taskKey);
        if ($task['deleted_at'] !== null) { return []; }
        $actors = $this->db->table('tm_task_watchers')->where('task_id', $task['id'])->orderBy('actor_ref')->limit(1001)->pluck('actor_ref')->all();
        if ($commentKey !== null) {
            $comment = $this->resources->get($context, 'comment', $commentKey);
            if ($comment['task_id'] !== $task['id'] || $comment['deleted_at'] !== null) { throw new DomainError('not_found'); }
            $actors = [...$actors, ...$this->db->table('tm_comment_mentions')->where('comment_id', $comment['id'])->pluck('actor_ref')->all()];
        }
        $actors = array_values(array_unique($actors)); if (count($actors) > 1000) { throw new DomainError('recipient_batch_required'); }
        return array_values(array_filter($actors, fn ($actor) => RecipientAccess::permitted($this->tasks, $context, $task['id'], $actor)));
    }
    public function restore(ExecutionContext $context, string $kind, string $key, int $version, string $operation): array
    {
        $this->authorize($context, $kind === 'comment' ? 'comment.edit_own' : 'attachment.add');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'resource_restore', 'kind' => $kind, 'key' => $key, 'version' => $version], function () use ($context, $kind, $key, $version, $operation) {
            $resource = $this->resources->get($context, $kind, $key); $task = $this->tasks->authorizedQuery($context, 'edit')->where('id', $resource['task_id'])->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$task) { throw new DomainError('not_found'); }
            if ($resource['author_ref'] !== $context->actor) { if ($kind !== 'comment') { throw new DomainError('access_denied'); } $this->authorize($context, 'comment.moderate'); }
            if ($resource['version'] !== $version) { throw new DomainError('version_conflict'); }
            if ($resource['deleted_at'] === null || $this->clock->now() > (new \DateTimeImmutable($resource['deleted_at'], new \DateTimeZone('UTC')))->modify('+30 days')) { throw new DomainError('resource_restore_unavailable'); }
            $table = $kind === 'comment' ? 'tm_comments' : 'tm_attachments';
            $this->db->table($table)->where('id', $resource['id'])->update(['deleted_at' => null, 'version' => $version + 1]);
            if ($kind === 'comment') { $this->db->table('tm_comment_revisions')->insert(['comment_id' => $resource['id'], 'version' => $version + 1, 'body' => $this->db->table($table)->where('id', $resource['id'])->value('body'), 'editor_ref' => $context->actor, 'occurred_at' => $this->now(), 'deleted' => false]); }
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, $kind.'_restored', $operation, ['resource_key' => $key], $task->id);
            return $this->resources->get($context, $kind, $key);
        }, fn ($result) => $this->resources->get($context, $kind, $result['public_key']));
    }
    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
