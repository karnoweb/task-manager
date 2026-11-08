<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SystemClock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$capsule = new Manager;
$capsule->addConnection(match (getenv('TM_TEST_DRIVER')) {
    'pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => ''],
    'mariadb' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'tm_acceptance_p3_72bd61', 'username' => 'tm_p3_72bd61', 'password' => 'local_fixture_72bd61', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
    default => throw new RuntimeException('A dedicated server database is required.'),
});
$db = $capsule->getConnection();
$clock = new SystemClock;
$auth = new ContextAuthorization;
$lifecycle = WorkerLifecycle::make($db, $clock);
$tasks = new TaskService($db, $clock, $auth, $lifecycle);
$projects = new ProjectService($db, $clock, $auth, new WorkflowService($db, $clock, $auth), $tasks, $lifecycle);
$context = new ExecutionContext('one', 'a', 'concurrent-project-create', capabilities: ['task.create', 'task.view', 'task.edit', 'project.view', 'project.edit', 'project.manage', 'workflow.view']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException('Barrier connection failed');
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed');
}
fclose($socket);
try {
    $task = $projects->createTask($context, $argv[2], 'task', 'Concurrent task', $argv[3]);
    echo json_encode(['key' => $task['public_key'], 'display' => $task['display_key']], JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    echo json_encode(['error' => $error->errorCode], JSON_THROW_ON_ERROR);
}
