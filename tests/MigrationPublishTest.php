<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\ServiceProvider;
use Karnoweb\TaskManager\TaskManagerServiceProvider;
use Orchestra\Testbench\TestCase;

final class MigrationPublishTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TaskManagerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.migrations.update_date_on_publish', true);
    }

    public function test_migrations_publish_with_the_shipped_filenames(): void
    {
        $source = dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $published = ServiceProvider::pathsToPublish(TaskManagerServiceProvider::class, 'task-manager-migrations');

        self::assertNotEmpty(ServiceProvider::pathsToPublish(TaskManagerServiceProvider::class, 'task-manager-config'));
        self::assertCount(1, $published);
        $from = array_key_first($published);
        self::assertSame(realpath($source), realpath($from));
        self::assertSame(database_path('migrations'), $published[$from]);
        self::assertNotContains(realpath($source), array_map(
            static fn (string $path): string|false => realpath($path),
            ServiceProvider::publishableMigrationPaths(),
        ));

        $expected = array_map('basename', glob($source.DIRECTORY_SEPARATOR.'*.php') ?: []);
        sort($expected);
        self::assertNotEmpty($expected);
        foreach ($expected as $name) {
            self::assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', $name);
        }

        $destination = database_path('migrations');
        $created = [];
        try {
            $this->artisan('vendor:publish', [
                '--tag' => 'task-manager-migrations',
                '--no-interaction' => true,
            ])->assertSuccessful();

            foreach ($expected as $name) {
                $path = $destination.DIRECTORY_SEPARATOR.$name;
                self::assertFileExists($path);
                self::assertFileEquals($source.DIRECTORY_SEPARATOR.$name, $path);
                $created[] = $path;
            }

            $rewritten = glob($destination.DIRECTORY_SEPARATOR.date('Y_m_d_').'*.php') ?: [];
            self::assertSame([], $rewritten);

            $name = '2026_10_20_000001_create_task_foundation';
            $resolved = $this->app->make('migrator')->getMigrationFiles([
                $source,
                $destination,
            ]);
            self::assertSame(realpath($destination.DIRECTORY_SEPARATOR.$name.'.php'), realpath($resolved[$name]));
        } finally {
            foreach ($created as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
