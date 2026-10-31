<?php

declare(strict_types=1);
use Karnoweb\TaskManager\Context\ContextFactory;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\BranchResolver;
use Karnoweb\TaskManager\Contracts\ScopeResolver;
use Karnoweb\TaskManager\Contracts\UserResolver;
use Karnoweb\TaskManager\TaskManager;
use Karnoweb\TaskManager\TaskSession;
use Karnoweb\TaskManager\Workflow\TransitionGuards;

require dirname(__DIR__).'/vendor/autoload.php';

function signature(ReflectionMethod $method): string
{
    $parameters = [];
    foreach ($method->getParameters() as $parameter) {
        $value = ($parameter->hasType() ? $parameter->getType().' ' : '').($parameter->isPassedByReference() ? '&' : '').($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            $value .= ' = '.($default === [] ? '[]' : var_export($default, true));
        }
        $parameters[] = $value;
    }

    return 'public '.($method->isStatic() ? 'static ' : '').'function '.$method->getName().'('.implode(', ', $parameters).')'.($method->hasReturnType() ? ': '.$method->getReturnType() : '');
}

$root = dirname(__DIR__);
$manager = new ReflectionClass(TaskManager::class);
$output = ['# Public service reference', '', 'Generated from PHP reflection by `php tools/build_api_reference.php`. No service is instantiated. Public locking helpers require an existing transaction; they are not controller endpoints.', '', 'Facade: `Karnoweb\\TaskManager\\Facades\\TaskManager`; injectable manager: `Karnoweb\\TaskManager\\TaskManager`.', ''];
$output[] = '## context()';
$output[] = '';
$output[] = '```text';
$output[] = signature($manager->getMethod('context')).';';
$output[] = '```';
$output[] = '';
$methods = $manager->getMethods(ReflectionMethod::IS_PUBLIC);
usort($methods, fn ($a, $b) => strcmp($a->name, $b->name));
foreach ($methods as $accessor) {
    $type = $accessor->getReturnType();
    if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $accessor->getNumberOfParameters() > 0) {
        continue;
    }
    $service = new ReflectionClass($type->getName());
    $output[] = '## '.$accessor->name.'()';
    $output[] = '';
    $output[] = '`'.$service->getName().'`';
    $output[] = '';
    $output[] = '```text';
    foreach ($service->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isConstructor()) {
            $output[] = signature($method).';';
        }
    }
    $output[] = '```';
    $output[] = '';
}
foreach ([TaskSession::class, TransitionGuards::class, ContextFactory::class, ExecutionContext::class, UserResolver::class, BranchResolver::class, ScopeResolver::class] as $class) {
    $output[] = '## '.$class;
    $output[] = '';
    $output[] = '```text';
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $output[] = signature($method).';';
    }
    $output[] = '```';
    $output[] = '';
}
@mkdir($root.'/docs/api', 0777, true);
file_put_contents($root.'/docs/api/reference.md', implode("\n", $output)."\n");
echo "Generated docs/api/reference.md\n";
