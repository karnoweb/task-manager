<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Karnoweb\TaskManager\Context\ContextFactory;
use Karnoweb\TaskManager\Contracts\BranchResolver;
use Karnoweb\TaskManager\Contracts\ScopeResolver;
use Karnoweb\TaskManager\Contracts\UserResolver;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class Scenario14IdentityScopeTest extends PackageTestCase
{
    public function test_complete_scenario_14(): void
    {

        $this->app->instance(UserResolver::class, fn () => Auth::user());
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
}
