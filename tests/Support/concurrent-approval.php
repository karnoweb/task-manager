<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Approval\ApprovalEngine;
use Karnoweb\TaskManager\Approval\ApprovalService;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\SystemClock;
use Karnoweb\TaskManager\Tests\Support\HostResponsibilityResolver;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$capsule = new Manager(); $capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']);
$db = $capsule->getConnection(); $clock = new SystemClock(); $auth = new ContextAuthorization(); $resolver = new HostResponsibilityResolver($db);
$workflows = new WorkflowService($db, $clock, $auth); $pipelines = new PipelineService($db, $clock, $auth, $workflows);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry(['crm.lead' => new HostSubjectHandler($db, 'crm.lead')]), $workflows, $pipelines, lifecycle: WorkerLifecycle::make($db, $clock));
$approvals = new ApprovalService($db, $clock, $auth, new ApprovalEngine($db, $clock, $resolver), $instances, $pipelines);
$context = new ExecutionContext('one', $argv[2], 'approval-worker', true, ['workflow.view', 'approval.vote']);
$socket = stream_socket_client($argv[1], $error, $message, 10); if (!$socket) { throw new RuntimeException($message); }
fwrite($socket, "ready\n"); if (fgets($socket) !== "go\n") { throw new RuntimeException('Barrier failed'); }
try { $result = $approvals->vote($context, $argv[3], 1, 2, 'vote-'.$argv[2], 'approve'); echo json_encode(['actor' => $argv[2], 'state' => $result['state']], JSON_THROW_ON_ERROR); }
catch (DomainError $error) { echo json_encode(['actor' => $argv[2], 'error' => $error->getMessage()], JSON_THROW_ON_ERROR); }
catch (Throwable $error) { fwrite(STDERR, $error->__toString()); exit(1); }
