<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskManagerServiceProvider;
use Karnoweb\TaskManager\TaskService;
use Orchestra\Testbench\TestCase;

abstract class PackageTestCase extends TestCase
{
    protected function getPackageProviders($app): array { return [TaskManagerServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $driver = getenv('TM_TEST_DRIVER') ?: 'sqlite';
        $app['config']->set('database.connections.testing', $driver === 'pgsql'
            ? ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']
            : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->run();
    }

    protected function context(string $actor = 'a', string $scope = 'one'): ExecutionContext { return new ExecutionContext($scope, $actor, 'test'); }
    protected function service(): TaskService { return $this->app->make(TaskService::class); }

    protected function error(string $expected, callable $operation): void
    {
        try { $operation(); self::fail('Expected '.$expected); }
        catch (DomainError $error) { self::assertSame($expected, $error->getMessage()); }
    }
}
