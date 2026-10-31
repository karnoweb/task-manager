<?php

namespace Karnoweb\TaskManager\Schema;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Resolve identifiers at operation time, never cache application configuration globally. */
final class Tables
{
    public static function prefix(): string
    {
        $container = Container::getInstance();
        $config = $container->bound('config') ? $container->make('config') : null;
        $prefix = $config === null ? 'tm_' : $config->get('task-manager.table_prefix', 'tm_');
        if (! is_string($prefix) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,11}\z/D', $prefix)) {
            throw new DomainError('table_prefix_invalid');
        }
        $connection = $config?->get('database.default');
        if ($connection !== null && $config->get('database.connections.'.$connection.'.prefix', '') !== '') {
            throw new DomainError('connection_table_prefix_unsupported');
        }

        return $prefix;
    }

    public static function assertConnection(Connection $db): void
    {
        if ($db->getTablePrefix() !== '') {
            throw new DomainError('connection_table_prefix_unsupported');
        }
        $prefix = self::prefix();
        if ($prefix !== 'tm_' && $db->getSchemaBuilder()->hasTable('tm_tasks') && ! $db->getSchemaBuilder()->hasTable(self::name('tasks'))) {
            throw new DomainError('table_prefix_migration_required');
        }
    }

    public static function name(string $name): string
    {
        if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/D', $name)) {
            throw new DomainError('table_name_invalid');
        }
        $name = self::prefix().$name;
        if (strlen($name) > 63) {
            throw new DomainError('table_name_too_long');
        }

        return $name;
    }

    /** Legacy tokens in raw SQL and qualified columns share the same name source. */
    public static function sql(string $sql): string
    {
        return preg_replace_callback('/\btm_([a-zA-Z0-9_]+)/', static fn (array $match): string => self::name($match[1]), $sql);
    }
}
