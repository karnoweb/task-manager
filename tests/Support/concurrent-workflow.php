<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\SystemClock;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']);
$db = $capsule->getConnection();
$clock = new SystemClock;
$auth = new ContextAuthorization;
$workflows = new WorkflowService($db, $clock, $auth);
$pipelines = new PipelineService($db, $clock, $auth, $workflows);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry(['crm.lead' => new HostSubjectHandler($db, 'crm.lead')]), $workflows, $pipelines, lifecycle: WorkerLifecycle::make($db, $clock));
$context = new ExecutionContext('one', 'a', 'concurrent-workflow', capabilities: ['workflow.view', 'workflow.transition']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException($message);
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed');
}
try {
    $result = $argv[2] === 'start'
        ? $instances->start($context, 'crm.lead', $argv[3], (int) $argv[4], $argv[5], pipelineKey: $argv[6] ?? null)
        : $instances->transition($context, $argv[3], 1, $argv[4], 'review');
    echo json_encode(['key' => $result['instance_key']], JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->__toString());
    exit(1);
}
