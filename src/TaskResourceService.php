<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Audited metadata foundation. File storage and downloads remain host-owned. */
final class TaskResourceService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly TaskService $tasks, private readonly ?\Karnoweb\TaskManager\Contracts\AuthorizationProvider $authorization = null) {}
    private function table(string $kind): string
    {
        return match ($kind) { 'comment' => 'tm_comments', 'attachment' => 'tm_attachments', default => throw new DomainError('resource_kind_invalid') };
    }
    private function validate(string $kind, array $data): array
    {
        if ($kind === 'comment') {
            if (!is_string($data['body'] ?? null) || trim($data['body']) === '' || mb_strlen($data['body']) > 20000 || array_diff(array_keys($data), ['body'])) { throw new DomainError('comment_invalid'); }
            $body = strip_tags($data['body']); if (trim($body) === '') { throw new DomainError('comment_invalid'); }
            return ['body' => $body];
        }
        if (!is_string($data['file_ref'] ?? null) || trim($data['file_ref']) === '' || strlen($data['file_ref']) > 255
            || !is_string($data['media_type'] ?? null) || strlen($data['media_type']) > 100 || !is_int($data['size'] ?? null) || $data['size'] < 0
            || array_diff(array_keys($data), ['file_ref', 'media_type', 'size'])) { throw new DomainError('attachment_invalid'); }
        return $data;
    }
    public function create(ExecutionContext $context, string $taskKey, string $kind, array $data, string $operation): array
    {
        $table = $this->table($kind); $values = $this->validate($kind, $data);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => $kind.'_create', 'task' => $taskKey, 'values' => $values], function () use ($context, $taskKey, $kind, $table, $values, $operation) {
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); }
            $key = (string) Str::uuid();
            $id = $this->db->table($table)->insertGetId(['public_key' => $key, 'scope_key' => $context->scope, 'task_id' => $task->id, 'author_ref' => $context->actor, 'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), ...$values]);
            if ($kind === 'comment') { $this->db->table('tm_comment_revisions')->insert(['comment_id' => $id, 'version' => 1, 'body' => $values['body'], 'editor_ref' => $context->actor, 'occurred_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]); }
            $this->record($context, $kind, $key, $task, 'created', $operation, $values);
            return $this->get($context, $kind, $key);
        }, fn ($r) => $this->get($context, $kind, $r['public_key']));
    }
    public function get(ExecutionContext $context, string $kind, string $key): array
    {
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $visible = $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id');
        $row = $this->db->table($this->table($kind))->where('scope_key', $context->scope)->where('public_key', $key)->whereIn('task_id', $visible)->first();
        if (!$row) { throw new DomainError('not_found'); }
        $result = (array) $row;
        if ($kind === 'comment') { $result['body'] = $row->deleted_at === null ? $row->body : ''; $result['body_html'] = htmlspecialchars($result['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        return $result;
    }
    public function change(ExecutionContext $context, string $kind, string $key, int $version, string $operation, ?array $data): array
    {
        $table = $this->table($kind); $values = $data === null ? null : $this->validate($kind, $data);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => $kind.'_change', 'key' => $key, 'version' => $version, 'values' => $values], function () use ($context, $kind, $key, $table, $version, $operation, $values) {
            $resource = $this->get($context, $kind, $key);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('id', $resource['task_id'])->lockForUpdate()->first();
            if (!$task) { throw new DomainError('access_denied'); }
            $moderation = $kind === 'comment' && ($resource['author_ref'] !== $context->actor || ($values !== null && ($resource['created_at'] === null || $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($resource['created_at'], new \DateTimeZone('UTC')))->getTimestamp() > 900)));
            if ($moderation) { $context->require('comment.moderate'); $this->authorization?->authorize($context, 'comment.moderate'); }
            elseif ($resource['author_ref'] !== $context->actor) { throw new DomainError('access_denied'); }
            $current = $this->db->table($table)->where('id', $resource['id'])->lockForUpdate()->first();
            if ($current->version !== $version) { throw new DomainError('version_conflict'); }
            if ($current->deleted_at !== null) { throw new DomainError('resource_deleted'); }
            $changes = ['version' => $version + 1, ...($values ?? ['deleted_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')])];
            $this->db->table($table)->where('id', $resource['id'])->update($changes);
            if ($kind === 'comment') {
                $this->db->table('tm_comment_revisions')->insert(['comment_id' => $resource['id'], 'version' => $version + 1, 'body' => $values['body'] ?? $current->body,
                    'editor_ref' => $context->actor, 'occurred_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), 'deleted' => $values === null]);
                $this->db->table($table)->where('id', $resource['id'])->update(['edited_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
            } elseif ($values !== null) { $this->db->table($table)->where('id', $resource['id'])->update(['storage_verified' => false]); }
            $this->record($context, $kind, $key, $task, $values === null ? 'deleted' : 'edited', $operation, $values ?? []);
            return $this->get($context, $kind, $key);
        }, fn ($r) => $this->get($context, $kind, $r['public_key']));
    }
    private function record(ExecutionContext $context, string $kind, string $key, object $task, string $action, string $operation, array $data): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, $kind.'_'.$action, $operation,
            ['resource_key' => $key, 'changed_fields' => array_keys($data), 'after' => $data], $task->id);
    }
}
