<?php

namespace Karnoweb\TaskManager\UI;

use Karnoweb\TaskManager\Exceptions\DomainError;

/** Trusted server configuration. Browser filters can only narrow these sources. */
final readonly class Surface
{
    public function __construct(public string $key, public array $sources, public array $pipelines = [], public array $handoffRoutes = [])
    {
        if ($key === '' || count($sources) > 20 || count($sources) !== count(array_unique($sources))) { throw new DomainError('surface_invalid'); }
        foreach ($sources as $source) { if (!preg_match('/^[a-z][a-z0-9._-]{1,79}$/D', $source)) { throw new DomainError('surface_invalid'); } }
    }

    public function allows(string $source): void
    {
        if (!in_array($source, $this->sources, true)) { throw new DomainError('not_found'); }
    }
}
