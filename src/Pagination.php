<?php

namespace Karnoweb\TaskManager;

use Illuminate\Container\Container;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** @internal Configurable everyday query limits; never relax the existing safety ceiling. */
final class Pagination
{
    public static function limit(int $limit, bool $omitted): int
    {
        $container = Container::getInstance();
        $config = $container->bound('config') ? $container->make('config') : null;
        $maximum = $config === null ? 100 : $config->get('task-manager.max_page_size', 100);
        if (! is_int($maximum) || $maximum < 1 || $maximum > 100) {
            throw new DomainError('pagination_configuration_invalid');
        }
        if ($omitted) {
            $limit = $config === null ? 30 : $config->get('task-manager.page_size', 30);
            if (! is_int($limit) || $limit < 1 || $limit > $maximum) {
                throw new DomainError('pagination_configuration_invalid');
            }
        }
        if ($limit < 1 || $limit > $maximum) {
            throw new DomainError('pagination_invalid');
        }

        return $limit;
    }
}
