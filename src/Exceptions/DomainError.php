<?php

namespace Karnoweb\TaskManager\Exceptions;

final class DomainError extends \RuntimeException
{
    public function __construct(string $message, public readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
