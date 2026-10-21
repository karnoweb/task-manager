<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\Responsibility\AssignmentEngine;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\SlaEngine;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\FrozenClock;
use Karnoweb\TaskManager\Tests\Support\HostResponsibilityResolver;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']);
$db = $capsule->getConnection();
$clock = new FrozenClock;
$auth = new ContextAuthorization;
$resolver = new HostResponsibilityResolver($db);
$workflows = new WorkflowService($db, $clock, $auth);
$pipelines = new PipelineService($db, $clock, $auth, $workflows);
$engine = new SlaEngine($db, $clock, new CalendarRegistry);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry(['crm.lead' => new HostSubjectHandler($db, 'crm.lead')]), $workflows, $pipelines, lifecycle: WorkerLifecycle::make($db, $clock, $engine));
$handoffs = new HandoffService($db, $clock, $auth, $instances, $workflows, new CaseService($db, $clock, $auth, $instances), new SlaService($db, $clock, $auth, $engine, $instances), new AssignmentEngine($db, $clock, $resolver));
$context = new ExecutionContext('one', 'a', 'handoff-worker', true, ['workflow.view', 'workflow.transition', 'pipeline.manage', 'case.manage', 'handoff.view', 'handoff.manage', 'sla.pause']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException($message);
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed');
}
try {
    if ($argv[2] === 'delegate') {
        $policy = ['reason_code' => 'contract_review', 'target_team' => 'contracts', 'returns' => ['approved' => ['transition' => 'review']]];
        $result = $handoffs->delegate($context, $argv[3], 1, $argv[4], (int) $argv[5], 'contracts', $policy, $argv[6]);
    } else {
        $result = $handoffs->settle($context, $argv[3], 1, $argv[4]);
    }
    echo json_encode(['key' => $result['handoff_key'], 'status' => $result['status']], JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->__toString());
    exit(1);
}
