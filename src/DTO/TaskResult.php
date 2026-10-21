<?php

namespace Karnoweb\TaskManager\DTO;

final readonly class TaskResult
{
    public function __construct(public string $publicKey, public string $displayKey, public string $title, public string $scope,
        public string $owner, public string $state, public int $version, public ?string $completedAt)
    {
    }

    public static function fromArray(array $task): self
    {
        return new self($task['public_key'], $task['display_key'], $task['title'], $task['scope_key'], $task['owner_ref'], $task['state'], $task['version'], $task['completed_at']);
    }
}
