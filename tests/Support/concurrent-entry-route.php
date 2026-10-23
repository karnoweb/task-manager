<?php

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\SystemClock;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
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
$workflows = new WorkflowService($db, $clock, $auth);
$pipelines = new PipelineService($db, $clock, $auth, $workflows);
$instances = new InstanceService($db, $clock, $auth, new SubjectRegistry, $workflows, $pipelines);
$provider = new class implements EntryRoutingProvider
{
    public function authorize(ExecutionContext $context, string $routingKey): void
    {
    }

    public function parent(ExecutionContext $context, string $routingKey): ?string
    {
        return null;
    }
};
$routing = new EntryRoutingService($db, $clock, $auth, $pipelines, $instances, $provider);
$context = new ExecutionContext('one', 'a', 'concurrent-route', capabilities: ['pipeline.manage', 'workflow.view']);
$socket = stream_socket_client($argv[1], $error, $message, 10);
if (! $socket) {
    throw new RuntimeException($message);
}
fwrite($socket, "ready\n");
if (fgets($socket) !== "go\n") {
    throw new RuntimeException('Barrier failed.');
}
fclose($socket);
try {
    $row = $routing->setDefault($context, 'support', $argv[2], 1, $argv[3]);
    echo json_encode(['version' => $row['version'], 'pipeline' => $row['pipeline_id']], JSON_THROW_ON_ERROR);
} catch (DomainError $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
