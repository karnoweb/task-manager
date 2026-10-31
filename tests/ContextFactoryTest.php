<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ContextFactory;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\BranchResolver;
use Karnoweb\TaskManager\Contracts\ScopeResolver;
use Karnoweb\TaskManager\Contracts\UserResolver;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Facades\TaskManager;

final class ContextFactoryTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UserResolver::class, fn () => Auth::user());
    }

    public function test_missing_resolver_does_not_fall_back_to_laravel_auth(): void
    {
        $this->app->offsetUnset(UserResolver::class);
        Auth::setUser(new GenericUser(['id' => 'authenticated']));
        $this->error('context_user_missing', fn () => TaskManager::context());
        self::assertSame('worker', TaskManager::context(new ExecutionContext('one', 'worker', 'explicit'))->actor);
    }

    public function test_authenticated_uuid_and_numeric_identifiers_are_preserved_and_correlations_are_fresh(): void
    {
        $factory = $this->app->make(ContextFactory::class);
        Auth::setUser(new GenericUser(['id' => '123e4567-e89b-42d3-a456-426614174000']));
        $a = $factory->make();
        self::assertSame('123e4567-e89b-42d3-a456-426614174000', $a->actor);
        self::assertSame('default', $a->scope);
        self::assertTrue(Str::isUuid($a->correlationId));
        self::assertNotSame($a->correlationId, $factory->make()->correlationId);
        Auth::setUser(new GenericUser(['id' => 0]));
        self::assertSame('0', $factory->make()->actor);
        config(['task-manager.user.identifier' => 'external_id']);
        Auth::setUser(new GenericUser(['id' => 1, 'external_id' => '00042']));
        self::assertSame('00042', $factory->make()->actor);
    }

    public function test_guest_cli_queue_and_explicit_service_context_fail_closed_or_preserve_explicit_identity(): void
    {
        $factory = $this->app->make(ContextFactory::class);
        $this->error('context_user_missing', fn () => $factory->make());
        $this->error('context_user_missing', fn () => TaskManager::tasks()->inContext());
        $worker = new ExecutionContext('legacy-org', 'worker:one', 'job:1', true, ['task.view']);
        self::assertSame($worker, $factory->make($worker));
        self::assertSame($worker, TaskManager::context($worker));
        $this->error('context_branch_conflict', fn () => $factory->make($worker, 'b'));
        $this->error('capability_denied', fn () => $this->service()->inContext($worker)->create('Denied', 'denied'));
        self::assertSame(0, DB::table('tm_tasks')->count());
    }

    public function test_branch_is_optional_and_does_not_implicitly_change_scope(): void
    {
        Auth::setUser(new GenericUser(['id' => 'a']));
        $factory = $this->app->make(ContextFactory::class);
        $this->error('context_branch_disabled', fn () => $factory->make(branch: 'north'));
        config(['task-manager.branch.enabled' => true]);
        $this->error('context_branch_resolver_required', fn () => $factory->make());
        $this->error('context_branch_resolver_required', fn () => $factory->make(branch: 'north'));
        $this->app->instance(BranchResolver::class, new class implements BranchResolver
        {
            public function resolve(object $user, int|string|null $requested): object|int|string|null
            {
                return in_array($requested, ['north', 'south', 0], true) ? $requested : null;
            }
        });
        config(['task-manager.branch.default_id' => 'north']);
        self::assertSame('branch:north', $factory->make()->scope);
        self::assertSame('branch:south', $factory->make(branch: 'south')->scope);
        self::assertSame('branch:0', $factory->make(branch: 0)->scope);
        $this->error('context_branch_denied', fn () => $factory->make(branch: 'foreign'));
        $this->app->instance(BranchResolver::class, fn () => 'north');
        $this->error('context_branch_denied', fn () => $factory->make(branch: 'south'));
        config(['task-manager.branch.enabled' => false]);
        self::assertSame('default', $factory->make()->scope);
    }

    public function test_request_time_resolvers_do_not_leak_users_branches_or_scopes_in_a_long_lived_factory(): void
    {
        config(['task-manager.branch.enabled' => true]);
        $factory = $this->app->make(ContextFactory::class);
        $this->app->instance(BranchResolver::class, fn ($user, $requested) => ($requested ?? Auth::user()?->branch) === (['a' => 'north', 'b' => 'south'][$user->getAuthIdentifier()] ?? null) ? (['a' => 'north', 'b' => 'south'][$user->getAuthIdentifier()] ?? null) : null);
        $this->app->instance(ScopeResolver::class, fn ($user, $branch) => 'branch:'.$branch);
        Auth::setUser(new GenericUser(['id' => 'a', 'branch' => 'north', 'organization' => 'org-a']));
        $a = $factory->make();
        $task = $this->service()->inContext($a)->create('Private A', 'a');
        Auth::setUser(new GenericUser(['id' => 'b', 'branch' => 'south', 'organization' => 'org-b']));
        $b = $factory->make();
        self::assertSame('branch:south', $b->scope);
        self::assertSame('b', $b->actor);
        $this->error('not_found', fn () => $this->service()->inContext($b)->get($task->publicKey));
        self::assertCount(0, $this->service()->inContext($b)->query());
        self::assertSame('Private A', $this->service()->inContext($a)->get($task->publicKey)->title);
        $this->error('context_branch_denied', fn () => $factory->make(branch: 'north'));
    }

    public function test_class_resolvers_and_models_work_with_cacheable_config_and_container_precedence(): void
    {
        $this->app->offsetUnset(UserResolver::class);
        config(['task-manager.branch.default_id' => 'B-2', 'task-manager.user.resolver' => ConfiguredUser::class, 'task-manager.user.model' => GenericUser::class,
            'task-manager.branch.enabled' => true, 'task-manager.branch.resolver' => ConfiguredBranch::class,
            'task-manager.branch.model' => HostBranch::class, 'task-manager.scope.resolver' => ConfiguredScope::class]);
        $context = TaskManager::context(branch: 'B-2');
        self::assertSame('host:user', $context->actor);
        self::assertSame('branch:B-2', $context->scope);
        $this->app->instance(UserResolver::class, fn () => new GenericUser(['id' => 'override']));
        self::assertSame('override', TaskManager::context()->actor);
        $file = tempnam(sys_get_temp_dir(), 'tm-config-');
        try {
            file_put_contents($file, '<?php return '.var_export(config('task-manager'), true).';');
            self::assertSame(config('task-manager'), require $file);
        } finally {
            unlink($file);
        }
        config(['task-manager.user.model' => HostBranch::class]);
        $this->error('context_model_invalid', fn () => TaskManager::context());
    }

    public function test_capabilities_are_server_owned_and_service_authorization_is_still_enforced(): void
    {
        Auth::setUser(new GenericUser(['id' => 'a']));
        config(['task-manager.context.capabilities_resolver' => ReadOnlyCapabilities::class]);
        self::assertSame(['task.view'], TaskManager::context()->capabilities);
        $this->error('capability_denied', fn () => TaskManager::tasks()->inContext()->create('Denied', 'caps'));
        self::assertSame(0, DB::table('tm_tasks')->count());
    }

    public function test_host_authorization_remains_mandatory_with_automatic_context(): void
    {
        Auth::setUser(new GenericUser(['id' => 'a']));
        $this->app->instance(AuthorizationProvider::class, new class implements AuthorizationProvider
        {
            public function authorize(ExecutionContext $context, string $capability): void
            {
                throw new DomainError('host_denied');
            }

            public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void
            {
            }
        });
        $this->error('host_denied', fn () => TaskManager::tasks()->inContext()->create('Denied', 'host'));
        self::assertSame(0, DB::table('tm_tasks')->count());
    }

    public function test_invalid_identity_configuration_and_caps_are_rejected_without_writes(): void
    {
        $this->app->instance(UserResolver::class, fn () => new GenericUser(['id' => true]));
        $this->error('context_identifier_invalid', fn () => TaskManager::context());
        $this->app->instance(UserResolver::class, fn () => new GenericUser(['id' => 'a']));
        config(['task-manager.context.capabilities' => [true]]);
        $this->error('context_capabilities_invalid', fn () => TaskManager::context());
        config(['task-manager.context.capabilities' => [], 'task-manager.scope.default' => null]);
        $this->error('context_identifier_invalid', fn () => TaskManager::context());
        self::assertSame(0, DB::table('tm_tasks')->count());
    }
}

final class ConfiguredUser implements UserResolver
{
    public function resolve(): ?object
    {
        return new GenericUser(['id' => 'host:user']);
    }
}
final readonly class HostBranch
{
    public function __construct(public string $id)
    {
    }

    public function getKey(): string
    {
        return $this->id;
    }
}
final class ConfiguredBranch implements BranchResolver
{
    public function resolve(object $user, int|string|null $requested): object|int|string|null
    {
        return $requested === null ? null : new HostBranch((string) $requested);
    }
}
final class ConfiguredScope implements ScopeResolver
{
    public function resolve(object $user, ?string $branch): int|string|null
    {
        return 'branch:'.$branch;
    }
}
final class ReadOnlyCapabilities
{
    public function __invoke(object $user, string $scope, ?string $branch): array
    {
        return ['task.view'];
    }
}
