<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Group\PostgresCommitEvidence;

final class NativeCommitEvidenceTest extends GroupTestCase
{
    public function test_actual_postgres_commit_timestamps_define_before_equal_and_after_cutoff(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL actual transaction commit timestamp evidence.');
        }
        self::assertSame('on', DB::selectOne("SELECT current_setting('track_commit_timestamp') AS value")->value);
        $provider = new PostgresCommitEvidence(DB::connection());
        $this->app->instance(CommitEvidenceProvider::class, $provider);
        [$group, $instances] = $this->campaignFixture(3);
        $commits = [];
        foreach ($instances as $index => $instance) {
            $this->reserve($instance, (string) $index, false);
            $intent = (array) DB::table('tm_milestone_intents')->where('instance_id', $instance['id'])->first();
            $committed = $provider->committedAt($this->manager(), $intent);
            self::assertNotNull($committed);
            $commits[] = $committed;
        }
        self::assertLessThan($commits[1], $commits[0]);
        self::assertGreaterThan($commits[1], $commits[2]);
        $this->clock->time = $commits[2]->modify('+1 second');
        foreach ($instances as $index => $instance) {
            $intent = DB::table('tm_milestone_intents')->where('instance_id', $instance['id'])->first();
            $verified = $this->milestones()->verify($this->manager(), $intent->intent_key, 2, 'native-verify-'.$index);
            self::assertSame($commits[$index]->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $verified['committed_at']);
        }
        DB::table('host_campaigns')->where('id', 'campaign-1')->update(['state' => 'closed', 'version' => 2, 'cutoff_at' => $commits[1]->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(2, $preview['counts']['continued']);
        self::assertSame(1, $preview['counts']['failed']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'native-close');
        self::assertSame(['continued' => 2, 'failed' => 1], $this->processAll($run['run_key'])['counts']);
    }

    public function test_uncommitted_transition_cannot_create_verified_evidence_and_rollback_removes_intent(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL in-progress transaction visibility.');
        }
        [$group, $instances] = $this->campaignFixture();
        $provider = new PostgresCommitEvidence(DB::connection());
        DB::beginTransaction();
        try {
            $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'reserve-uncommitted', 'reserve');
            $intent = (array) DB::table('tm_milestone_intents')->first();
            self::assertNull($provider->committedAt($this->manager(), $intent));
            try {
                $this->milestones()->verify($this->manager(), $intent['intent_key'], 2, 'invalid-verify');
                self::fail();
            } catch (DomainError $error) {
                self::assertSame('commit_evidence_requires_committed_boundary', $error->getMessage());
            }
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, DB::table('tm_milestone_intents')->count());
        self::assertSame(0, DB::table('tm_process_milestones')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }
}
