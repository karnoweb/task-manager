<?php

namespace Karnoweb\TaskManager\UI;

use Karnoweb\TaskManager\Exceptions\DomainError;

final class CardIdentity
{
    public static function make(string $source, string $key): string { return $source.':'.$key; }
    public static function parse(string $id, Surface $surface): array
    {
        $parts = explode(':', $id, 2);
        if (count($parts) !== 2 || $parts[1] === '') { throw new DomainError('not_found'); }
        $surface->allows($parts[0]);
        return $parts;
    }
}
