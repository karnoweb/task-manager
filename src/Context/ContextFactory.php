<?php

namespace Karnoweb\TaskManager\Context;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Contracts\BranchResolver;
use Karnoweb\TaskManager\Contracts\ScopeResolver;
use Karnoweb\TaskManager\Contracts\UserResolver;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Resolve on every call; no request identity or context is cached here. */
final readonly class ContextFactory
{
    public function __construct(private Container $container, private Repository $config)
    {
    }

    public function make(?ExecutionContext $context = null, int|string|null $branch = null): ExecutionContext
    {
        if ($context !== null) {
            if ($branch !== null) {
                throw new DomainError('context_branch_conflict');
            }

            $context->validateTenant();

            return $context;
        }
        $resolver = $this->resolver(UserResolver::class, 'user.resolver');
        $user = $resolver === null ? null : $this->call($resolver, UserResolver::class);
        if ($user === null) {
            throw new DomainError('context_user_missing');
        }
        if (! is_object($user)) {
            throw new DomainError('context_user_invalid');
        }
        $this->model($user, 'user.model');
        $actor = $this->identity($this->identifier($user, 'user.identifier', 'getAuthIdentifier'));
        $branchId = $this->branch($user, $branch);
        $scopeResolver = $this->resolver(ScopeResolver::class, 'scope.resolver');
        $scope = $scopeResolver === null ? ($branchId === null ? $this->config->get('task-manager.scope.default', 'default') : TenantBoundary::scope($branchId)) : $this->call($scopeResolver, ScopeResolver::class, $user, $branchId);
        $scope = $this->identity($scope);
        if ($branchId !== null) {
            $canonical = TenantBoundary::scope($branchId);
            if ($scopeResolver !== null && $scope !== $canonical) {
                throw new DomainError('context_branch_scope_conflict');
            }
            $scope = $canonical;
        }
        $capabilities = $this->config->get('task-manager.context.capabilities', []);
        $capabilityResolver = $this->config->get('task-manager.context.capabilities_resolver');
        if ($capabilityResolver !== null) {
            $callback = is_string($capabilityResolver) ? $this->container->make($capabilityResolver) : $capabilityResolver;
            if (! is_callable($callback)) {
                throw new DomainError('context_resolver_invalid');
            }
            $capabilities = $callback($user, $scope, $branchId);
        }
        if (! is_array($capabilities) || ! array_is_list($capabilities)) {
            throw new DomainError('context_capabilities_invalid');
        }
        foreach ($capabilities as $capability) {
            if (! is_string($capability) || trim($capability) === '' || strlen($capability) > 120) {
                throw new DomainError('context_capabilities_invalid');
            }
        }

        return new ExecutionContext($scope, $actor, (string) Str::uuid(), capabilities: array_values(array_unique($capabilities)));
    }

    private function branch(object $user, int|string|null $explicit): ?string
    {
        $enabled = $this->config->get('task-manager.branch.enabled', false);
        if (! is_bool($enabled)) {
            throw new DomainError('context_branch_configuration_invalid');
        }
        if (! $enabled) {
            if ($explicit !== null) {
                throw new DomainError('context_branch_disabled');
            }

            return null;
        }
        $requested = $explicit ?? $this->config->get('task-manager.branch.default_id');
        if ($requested !== null) {
            $this->identity($requested);
        }
        $resolver = $this->resolver(BranchResolver::class, 'branch.resolver');
        if ($resolver === null) {
            throw new DomainError('context_branch_resolver_required');
        }
        $value = $this->call($resolver, BranchResolver::class, $user, $requested);
        if ($value === null) {
            throw new DomainError('context_branch_denied');
        }
        if (is_object($value)) {
            $this->model($value, 'branch.model');
            $value = $this->identifier($value, 'branch.identifier', 'getKey');
        } elseif ($this->config->get('task-manager.branch.model') !== null) {
            // A configured model is a real type constraint, not unused metadata.
            throw new DomainError('context_model_invalid');
        }

        $resolved = $this->identity($value);
        if ($requested !== null && $resolved !== (string) $requested) {
            throw new DomainError('context_branch_denied');
        }

        return $resolved;
    }

    /** Explicit actors are trusted host identities; branch membership is always rechecked. */
    public function validateExplicit(ExecutionContext $context): void
    {
        if (! $this->config->get('task-manager.branch.enabled', false)) {
            return;
        }
        if (! str_starts_with($context->scope, 'branch:')) {
            throw new DomainError('context_branch_scope_conflict');
        }
        $branch = substr($context->scope, 7);
        $actor = new ActorIdentity($context->actor, $context->serviceActor);
        $resolved = $this->branch($actor, $branch);
        if ($resolved === null || TenantBoundary::scope($resolved) !== $context->scope) {
            throw new DomainError('context_branch_scope_conflict');
        }
    }

    private function resolver(string $contract, string $key): mixed
    {
        if ($this->container->bound($contract)) {
            return $this->container->make($contract);
        }
        $resolver = $this->config->get('task-manager.'.$key);

        return is_string($resolver) ? $this->container->make($resolver) : $resolver;
    }

    private function call(mixed $resolver, string $contract, mixed ...$arguments): mixed
    {
        if ($resolver instanceof $contract) {
            return $resolver->resolve(...$arguments);
        }
        if (is_callable($resolver)) {
            return $resolver(...$arguments);
        }

        throw new DomainError('context_resolver_invalid');
    }

    private function model(object $value, string $key): void
    {
        $model = $this->config->get('task-manager.'.$key);
        if ($model !== null && (! is_string($model) || ! $value instanceof $model)) {
            throw new DomainError('context_model_invalid');
        }
    }

    private function identifier(object $value, string $key, string $method): mixed
    {
        $attribute = $this->config->get('task-manager.'.$key);
        if ($attribute !== null) {
            if (! is_string($attribute) || trim($attribute) === '') {
                throw new DomainError('context_identifier_invalid');
            }

            return data_get($value, $attribute);
        }

        return method_exists($value, $method) ? $value->{$method}() : data_get($value, 'id');
    }

    private function identity(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || trim((string) $value) === '' || strlen((string) $value) > 120) {
            throw new DomainError('context_identifier_invalid');
        }

        return (string) $value;
    }
}
