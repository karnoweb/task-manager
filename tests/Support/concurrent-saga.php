<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Handoff\ExternalSagaService;
use Karnoweb\TaskManager\Handoff\SubjectRouteRegistry;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\FrozenClock;
use Karnoweb\TaskManager\Tests\Support\HostRemoteRouter;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $argv[3], 'prefix' => '', 'busy_timeout' => 10000], 'remote_fixture');
$db = $capsule->getConnection();
$clock = new FrozenClock;
$auth = new ContextAuthorization;
$workflows = new WorkflowService($db, $clock, $auth);
$pipelines = new PipelineService($db, $clock, $auth, $workflows);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry(['support.ticket' => new HostSubjectHandler($db, 'support.ticket')]), $workflows, $pipelines, lifecycle: WorkerLifecycle::make($db, $clock));
$remote = new HostRemoteRouter($capsule->getConnection('remote_fixture'), $db);
$sagas = new ExternalSagaService($db, $clock, $auth, $instances, new CaseService($db, $clock, $auth, $instances), new SubjectRouteRegistry(remote: ['ticket.remote-v1' => $remote]));
$context = new ExecutionContext('one', 'a', 'saga-worker', true, ['workflow.view', 'workflow.transition', 'case.manage', 'saga.manage']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException($message);
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed');
}
try {
    $result = $sagas->execute($context, $argv[2]);
    echo json_encode(['state' => $result['state']], JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    echo json_encode(['error' => $error->errorCode], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->__toString());
    exit(1);
}
