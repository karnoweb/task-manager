<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AttachmentStorage;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class LocalAttachmentStorage implements AttachmentStorage
{
    private array $files = [];

    public function put(string $reference, string $scope, string $owner, string $bytes): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tm-attachment-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create attachment fixture.');
        }
        file_put_contents($path, $bytes);
        $this->files[$reference] = compact('path', 'scope', 'owner');
    }

    public function inspect(ExecutionContext $context, string $reference): array
    {
        $file = $this->files[$reference] ?? null;
        if (! $file || $file['scope'] !== $context->scope || $file['owner'] !== $context->actor) {
            throw new DomainError('not_found');
        }

        return ['file_ref' => $reference, 'media_type' => 'text/plain', 'size' => filesize($file['path'])];
    }

    public function read(ExecutionContext $context, string $reference): mixed
    {
        $file = $this->files[$reference] ?? null;
        if (! $file || $file['scope'] !== $context->scope) {
            throw new DomainError('not_found');
        }

        return fopen($file['path'], 'rb');
    }

    public function __destruct()
    {
        foreach ($this->files as $file) {
            if (is_file($file['path'])) {
                unlink($file['path']);
            }
        }
    }
}
