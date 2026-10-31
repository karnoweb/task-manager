<?php

namespace Karnoweb\TaskManager\Enterprise;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ExportStorage;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class LocalExportStorage implements ExportStorage
{
    public function __construct(private string $directory, private int $maxBytes = 104857600)
    {
        if ($directory === '' || $maxBytes < 1 || $maxBytes > 1073741824) {
            throw new DomainError('export_storage_config_invalid');
        }
    }

    private function folder(ExecutionContext $context): string
    {
        if (is_link($this->directory)) {
            throw new DomainError('export_storage_unsafe');
        }$folder = rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.hash('sha256', $context->scope.':'.$context->actor);
        if (is_link($folder) || (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder))) {
            throw new DomainError('export_storage_unavailable');
        }

        return $folder;
    }

    private function path(ExecutionContext $context, string $reference): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $reference)) {
            throw new DomainError('not_found');
        }

        return $this->folder($context).DIRECTORY_SEPARATOR.$reference;
    }

    public function store(ExecutionContext $context, iterable $chunks): string
    {
        $context->validateTenant();
        $reference = bin2hex(random_bytes(32));
        $path = $this->path($context, $reference);
        $stream = fopen($path, 'x+b');
        if (! $stream) {
            throw new DomainError('export_storage_unavailable');
        }chmod($path, 0600);
        $size = 0;
        try {
            foreach ($chunks as $chunk) {
                if (! is_string($chunk) || ($size += strlen($chunk)) > $this->maxBytes) {
                    throw new DomainError('export_size_limit');
                }$offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($stream, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new DomainError('export_write_failed');
                    }$offset += $written;
                }
            }fflush($stream);
            fclose($stream);

            return $reference;
        } catch (\Throwable $error) {
            fclose($stream);
            if (is_file($path)) {
                unlink($path);
            }throw $error;
        }
    }

    public function read(ExecutionContext $context, string $reference): mixed
    {
        $context->validateTenant();
        $path = $this->path($context, $reference);
        if (is_link($path) || ! is_file($path)) {
            throw new DomainError('not_found');
        }$stream = fopen($path, 'rb');
        if (! $stream) {
            throw new DomainError('not_found');
        }

        return $stream;
    }

    public function delete(ExecutionContext $context, string $reference): void
    {
        $context->validateTenant();
        $path = $this->path($context, $reference);
        if (is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }
}
