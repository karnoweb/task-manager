<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Group\GroupSourceRegistry;
use Karnoweb\TaskManager\Group\MilestoneService;
use Karnoweb\TaskManager\Tests\Support\CommittedFixtureLedger;
use Karnoweb\TaskManager\Tests\Support\HostCampaignProvider;

abstract class GroupTestCase extends HandoffTestCase
{
    protected CommittedFixtureLedger $commitLedger;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('host_campaigns', function (Blueprint $t) {
            $t->string('id', 120)->primary();
            $t->string('scope_key', 120);
            $t->string('manager', 120);
            $t->string('state', 30)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('cutoff_at', 6)->nullable();
        });
        $this->commitLedger = new CommittedFixtureLedger(DB::connection());
        $this->app->instance(CommitEvidenceProvider::class, $this->commitLedger);
        $this->app->instance(GroupSourceRegistry::class, new GroupSourceRegistry(['crm.campaign' => new HostCampaignProvider(DB::connection())]));
    }

    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'group-test', capabilities: [...$base->capabilities, 'group.view', 'group.manage', 'group.preview', 'group.execute', 'milestone.verify']);
    }

    protected function groupService(): GroupService
    {
        return $this->app->make(GroupService::class);
    }

    protected function milestones(): MilestoneService
    {
        return $this->app->make(MilestoneService::class);
    }

    protected function campaignFixture(int $count = 1, bool $withSla = false): array
    {
        $definition = ['initial' => 'new', 'states' => ['new' => ['terminal' => 'none'], 'reservation' => ['terminal' => 'none'], 'won' => ['terminal' => 'success'], 'failed' => ['terminal' => 'failure']],
            'transitions' => ['reserve' => ['from' => 'new', 'to' => 'reservation', 'milestones' => ['reservation_passed']], 'enter_reservation' => ['from' => 'new', 'to' => 'reservation'],
                'win' => ['from' => 'new', 'to' => 'won'], 'fail_new' => ['from' => 'new', 'to' => 'failed', 'reason_codes' => ['campaign_ended']],
                'fail_reservation' => ['from' => 'reservation', 'to' => 'failed', 'reason_codes' => ['campaign_ended']]]];
        $definition['transitions']['contract_return'] = ['from' => 'reservation', 'to' => 'reservation'];
        $r = $this->workflows()->publish($this->manager(), 'campaign-sales', 0, 'campaign-workflow', $definition);
        $stages = ['new' => ['state' => 'new'], 'reservation' => ['state' => 'reservation'], 'won' => ['state' => 'won'], 'failed' => ['state' => 'failed']];
        $pipeline = $this->pipelines()->create($this->manager(), 'Campaign sales', $r['id'], $stages, 'campaign-pipeline');
        $contract = $this->pipelines()->create($this->manager(), 'Contracts', $r['id'], $stages, 'contract-pipeline');
        DB::table('host_campaigns')->insert(['id' => 'campaign-1', 'scope_key' => 'one', 'manager' => 'a']);
        $policy = ['protected_milestones' => ['reservation_passed'], 'failure_transitions' => ['new' => 'fail_new', 'reservation' => 'fail_reservation'], 'reason_code' => 'campaign_ended'];
        $group = $this->groupService()->register($this->manager(), 'crm.campaign', 'campaign-1', $policy, 'campaign-group');
        $instances = [];
        $references = [];
        if ($withSla) {
            $this->policy()->publishSla($this->manager(), $pipeline['pipeline_key'], 'new', 1, 'early-sla', ['duration_seconds' => 60]);
            $this->policy()->publishSla($this->manager(), $pipeline['pipeline_key'], 'reservation', 2, 'reservation-sla', ['duration_seconds' => 172800, 'pause_reasons' => ['handoff'], 'max_pause_seconds' => 86400]);
        }
        for ($index = 0; $index < $count; $index++) {
            if ($withSla && $index === 12) {
                $this->policy()->publishSla($this->manager(), $pipeline['pipeline_key'], 'new', 3, 'later-sla', ['duration_seconds' => 86400]);
            }
            $this->host('lead-'.$index);
            $instance = $this->instances()->start($this->manager(), 'crm.lead', 'lead-'.$index, $r['id'], 'campaign-start-'.$index, pipelineKey: $pipeline['pipeline_key']);
            $instances[] = $instance;
            $references[] = ['instance_key' => $instance['instance_key'], 'version' => 1];
        }
        if ($references) {
            $group = $this->groupService()->enroll($this->manager(), $group['group_key'], 1, $references, 'campaign-enroll');
        }

        return [$group, $instances, $pipeline, $contract];
    }

    protected function campaignHundred(bool $withSla = false): array
    {
        [$group, $instances, $pipeline, $contract] = $this->campaignFixture(100, $withSla);
        $handoffs = [];
        foreach (range(75, 89) as $index) {
            $instances[$index] = $this->reserve($instances[$index], (string) $index);
            if ($index < 80) {
                $handoffs[] = $this->handoffs()->delegate($this->manager(), $instances[$index]['instance_key'], 2, $contract['pipeline_key'], $instances[$index]['workflow_revision_id'], 'contracts',
                    ['reason_code' => 'contract_review', 'target_team' => 'contracts', 'returns' => ['approved' => ['transition' => 'contract_return']]], 'delegate-'.$index);
            }
        }
        foreach (range(90, 99) as $index) {
            $instances[$index] = $this->instances()->transition($this->manager(), $instances[$index]['instance_key'], 1, 'win-'.$index, 'win');
        }

        return [$group, $instances, $handoffs];
    }

    protected function reserve(array $instance, string $suffix, bool $verify = true): array
    {
        $result = $this->instances()->transition($this->manager(), $instance['instance_key'], $instance['version'], 'reserve-'.$suffix, 'reserve');
        $intent = DB::table('tm_milestone_intents')->where('instance_id', $instance['id'])->orderByDesc('id')->first();
        if ($verify) {
            $this->commitLedger->acknowledge($intent->intent_key, $this->clock->now());
            $this->milestones()->verify($this->manager(), $intent->intent_key, $result['version'], 'verify-'.$suffix);
        }

        return $result;
    }

    protected function closeCampaign(): void
    {
        DB::table('host_campaigns')->where('id', 'campaign-1')->update(['state' => 'closed', 'version' => 2, 'cutoff_at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }

    protected function processAll(string $runKey, int $chunk = 25): array
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $result = $this->groupService()->process($this->manager(), $runKey, $chunk);
            if ($result['state'] === 'complete') {
                return $this->groupService()->report($this->manager(), $runKey);
            }
        }
        throw new \RuntimeException('Group processing did not finish');
    }
}
