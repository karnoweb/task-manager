<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\TaskService;

$capsule = new Manager();
$capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (!$socket) { throw new RuntimeException($message); }
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") { throw new RuntimeException('Barrier failed'); }
try {
    $service = new TaskService($capsule->getConnection());
    $context = new ExecutionContext('race', 'actor', 'race-test');
    $result = ($argv[2] ?? 'create') === 'update'
        ? $service->mutate($context, $argv[3], 1, $argv[4], 'update', ['priority' => 'high'])
        : $service->create($context, 'Concurrent', 'same-operation');
    echo json_encode(['id' => $result['public_key']], JSON_THROW_ON_ERROR);
} catch (\Karnoweb\TaskManager\Exceptions\DomainError $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->__toString());
    exit(1);
}
