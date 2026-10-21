<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;
use Karnoweb\TaskManager\Responsibility\AssignmentService;
use Karnoweb\TaskManager\Tests\Support\HostResponsibilityResolver;

abstract class AssignmentTestCase extends SlaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('host_people', function (Blueprint $t) {
            $t->string('scope_key', 120);
            $t->string('actor', 120);
            $t->string('team', 120);
            $t->boolean('active')->default(true);
            $t->boolean('can_assign')->default(true);
            $t->boolean('can_approve')->default(true);
            $t->primary(['scope_key', 'actor']);
        });
        foreach (['a' => 'sales', 'b' => 'sales', 'c' => 'contracts', 'd' => 'contracts'] as $actor => $team) {
            DB::table('host_people')->insert(['scope_key' => 'one', 'actor' => $actor, 'team' => $team]);
        }
        $this->app->instance(ResponsibilityResolver::class, new HostResponsibilityResolver(DB::connection()));
    }

    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'assignment-test', capabilities: [...$base->capabilities, 'assignment.manage']);
    }

    protected function assignments(): AssignmentService
    {
        return $this->app->make(AssignmentService::class);
    }

    protected function assignableInstance(): array
    {
        $r = $this->sales();
        $this->host();

        return $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
    }
}
