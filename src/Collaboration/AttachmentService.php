<?php

namespace Karnoweb\TaskManager\Collaboration;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AttachmentStorage;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskResourceService;
use Karnoweb\TaskManager\TaskService;

final class AttachmentService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly TaskResourceService $resources, private readonly AttachmentStorage $storage) {}
    private function authorize(ExecutionContext $context, string $capability): void { $context->require($capability); $this->authorization->authorize($context, $capability); }
    public function attach(ExecutionContext $context, string $taskKey, string $reference, string $operation): array
    {
        $this->authorize($context, 'attachment.add'); $this->tasks->get($context, $taskKey);
        if (trim($reference) === '' || strlen($reference) > 255) { throw new DomainError('attachment_invalid'); }
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'file_attach_verified', 'task' => $taskKey, 'reference' => $reference], function () use ($context, $taskKey, $reference, $operation) {
            $data = $this->storage->inspect($context, $reference);
            if (($data['file_ref'] ?? null) !== $reference || !is_int($data['size'] ?? null) || $data['size'] < 0 || $data['size'] > 100 * 1024 * 1024 || !is_string($data['media_type'] ?? null)) { throw new DomainError('attachment_inspection_invalid'); }
            $file = $this->resources->create($context, $taskKey, 'attachment', ['file_ref' => $reference, 'media_type' => $data['media_type'], 'size' => $data['size']], 'attachment-content:'.hash('sha256', $operation));
            $this->db->table('tm_attachments')->where('id', $file['id'])->update(['storage_verified' => true]); return $this->resources->get($context, 'attachment', $file['public_key']);
        }, fn ($result) => $this->resources->get($context, 'attachment', $result['public_key']));
    }
    public function issueTicket(ExecutionContext $context, string $taskKey, string $attachmentKey, int $lifetimeSeconds = 120): array
    {
        $this->authorize($context, 'attachment.download');
        if ($lifetimeSeconds < 1 || $lifetimeSeconds > 300) { throw new DomainError('ticket_lifetime_invalid'); }
        return $this->db->transaction(function () use ($context, $taskKey, $attachmentKey, $lifetimeSeconds) {
            $task = $this->tasks->authorizedQuery($context)->where('public_key', $taskKey)->whereNull('deleted_at')->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); }
            $file = $this->file($context, $taskKey, $attachmentKey);
            if ($this->db->table('tm_attachment_tickets')->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('attachment_id', $file['id'])->whereNull('revoked_at')->where('expires_at', '>', $this->stamp($this->clock->now()))->count() >= 10) { throw new DomainError('ticket_limit'); }
            $token = bin2hex(random_bytes(32)); $expires = $this->clock->now()->modify('+'.$lifetimeSeconds.' seconds');
            $id = $this->db->table('tm_attachment_tickets')->insertGetId(['token_hash' => hash('sha256', $token), 'scope_key' => $context->scope, 'attachment_id' => $file['id'], 'attachment_version' => $file['version'], 'actor_ref' => $context->actor, 'expires_at' => $this->stamp($expires)]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'attachment_ticket_issued', 'file-ticket:'.$id, ['resource_key' => $attachmentKey, 'expires_at' => $this->stamp($expires)], $task->id);
            return ['token' => $token, 'expires_at' => $this->stamp($expires)];
        });
    }
    public function download(ExecutionContext $context, string $taskKey, string $attachmentKey, string $token): mixed
    {
        $this->authorize($context, 'attachment.download');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new DomainError('not_found'); }
        $file = $this->file($context, $taskKey, $attachmentKey);
        $ticket = $this->db->table('tm_attachment_tickets')->where('token_hash', hash('sha256', $token))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)
            ->where('attachment_id', $file['id'])->where('attachment_version', $file['version'])->whereNull('revoked_at')->where('expires_at', '>', $this->stamp($this->clock->now()))->exists();
        if (!$ticket) { throw new DomainError('not_found'); }
        return $this->storage->read($context, $file['file_ref']);
    }
    private function file(ExecutionContext $context, string $taskKey, string $attachmentKey): array
    {
        $task = $this->tasks->get($context, $taskKey); $file = $this->resources->get($context, 'attachment', $attachmentKey);
        if ($task['deleted_at'] !== null || $file['task_id'] !== $task['id'] || $file['deleted_at'] !== null || !$file['storage_verified']) { throw new DomainError('not_found'); }
        return $file;
    }
    private function stamp(\DateTimeImmutable $time): string { return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
