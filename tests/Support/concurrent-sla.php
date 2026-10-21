<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\SlaEngine;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\FrozenClock;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']);
$db = $capsule->getConnection();
$clock = new FrozenClock(new DateTimeImmutable($argv[2]));
$auth = new ContextAuthorization;
$engine = new SlaEngine($db, $clock, new CalendarRegistry);
$workflows = new WorkflowService($db, $clock, $auth);
$pipelines = new PipelineService($db, $clock, $auth, $workflows);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry(['crm.lead' => new HostSubjectHandler($db, 'crm.lead')]), $workflows, $pipelines, lifecycle: WorkerLifecycle::make($db, $clock, $engine));
$sla = new SlaService($db, $clock, $auth, $engine, $instances);
$context = new ExecutionContext('one', 'a', 'sla-worker', true, ['sla.run', 'workflow.view']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException($message);
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed');
}
try {
    echo json_encode($sla->run($context), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->__toString());
    exit(1);
}
