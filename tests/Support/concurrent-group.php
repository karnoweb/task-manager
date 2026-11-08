<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Events\Dispatcher;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Group\GroupSourceRegistry;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\FrozenClock;
use Karnoweb\TaskManager\Tests\Support\HostCampaignProvider;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\Support\WorkerLifecycle;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

$profile = getenv('TM_TEST_DRIVER') ?: 'pgsql';
$configuration = match ($profile) {
    'pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public'],
    'mariadb' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'tm_acceptance_p3_72bd61', 'username' => 'tm_p3_72bd61', 'password' => 'local_fixture_72bd61', 'prefix' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true],
    default => throw new RuntimeException('Unknown dedicated group worker database profile.'),
};
$capsule = new Manager;
$capsule->addConnection($configuration);
$db = $capsule->getConnection();
$clock = isset($argv[4]) ? new FrozenClock(new DateTimeImmutable($argv[4])) : new FrozenClock;
$auth = new ContextAuthorization;
$workflows = new WorkflowService($db, $clock, $auth);
$pipelines = new PipelineService($db, $clock, $auth, $workflows);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry(['crm.lead' => new HostSubjectHandler($db, 'crm.lead')]), $workflows, $pipelines, lifecycle: WorkerLifecycle::make($db, $clock));
$groups = new GroupService($db, $clock, $auth, $instances, new GroupSourceRegistry(['crm.campaign' => new HostCampaignProvider($db)]));
$context = new ExecutionContext('one', 'a', 'group-worker', true, ['workflow.view', 'workflow.transition', 'group.view', 'group.execute']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException($message);
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed');
}
try {
    if ($argv[3] === 'crash') {
        $groups->process($context, $argv[2], 40);
        $events = new Dispatcher(new Container);
        $db->setEventDispatcher($events);
        $events->listen(TransactionCommitted::class, function () {
            fwrite(STDERR, 'injected_crash_after_40_and_durable_claim');
            exit(70);
        });
        $groups->process($context, $argv[2], 60);
        throw new RuntimeException('Crash injection did not execute');
    }
    echo json_encode($groups->process($context, $argv[2], (int) $argv[3]), JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    echo json_encode(['error' => $error->errorCode], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->__toString());
    exit(1);
}
