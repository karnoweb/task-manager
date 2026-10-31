<?php

namespace Karnoweb\TaskManager\Tests\Benchmarks;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\Tests\GroupTestCase;

/** Explicit opt-in benchmark, outside the normal tests/ suite. */
final class GroupScaleBenchmarkTest extends GroupTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (getenv('TM_TEST_DRIVER') !== 'pgsql') {
            throw new \RuntimeException('This benchmark requires the dedicated PostgreSQL scale database.');
        }
        $app['config']->set('database.connections.testing.database', 'tm_scale_p3_2c6b9e');
    }

    private function seedScaleCohort(array $group, array $pipeline, int $count, string $prefix): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $snapshot = json_encode(['primary' => 'a', 'secondary' => [], 'policy_id' => null, 'max_assignees' => 1, 'allow_unassigned' => false]);
        for ($offset = 0; $offset < $count; $offset += 1000) {
            DB::transaction(function () use ($group, $pipeline, $offset, $count, $prefix, $now, $snapshot) {
                $subjects = [];
                $permissions = [];
                $instances = [];
                $ids = [];
                for ($index = $offset; $index < min($count, $offset + 1000); $index++) {
                    $id = $prefix.'-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
                    $ids[] = $id;
                    $subjects[] = ['id' => $id, 'scope_key' => 'one', 'type' => 'crm.lead', 'title' => 'Synthetic scale fixture'];
                    $permissions[] = ['subject_id' => $id, 'actor' => 'a', 'can_edit' => true];
                    $instances[] = ['instance_key' => (string) Str::uuid(), 'scope_key' => 'one', 'subject_type' => 'crm.lead', 'subject_id' => $id, 'slot_key' => 'main',
                        'workflow_key' => 'campaign-sales', 'workflow_revision_id' => $pipeline['workflow_revision_id'], 'current_state_key' => 'new', 'owner_ref' => 'a', 'primary_assignee' => 'a',
                        'pipeline_id' => $pipeline['id'], 'active_stage_key' => 'new', 'started_at' => $now, 'assignment_snapshot' => $snapshot];
                }
                DB::table('host_subjects')->insert($subjects);
                DB::table('host_permissions')->insert($permissions);
                DB::table(Tables::name('workflow_instances'))->insert($instances);
                $rows = DB::table(Tables::name('workflow_instances'))->where('scope_key', 'one')->where('subject_type', 'crm.lead')->whereIn('subject_id', $ids)->get(['id', 'subject_id']);
                $slots = [];
                $visits = [];
                $members = [];
                foreach ($rows as $row) {
                    $slots[] = ['scope_key' => 'one', 'subject_type' => 'crm.lead', 'subject_id' => $row->subject_id, 'slot_key' => 'main', 'instance_id' => $row->id];
                    $visits[] = ['scope_key' => 'one', 'instance_id' => $row->id, 'pipeline_id' => $pipeline['id'], 'stage_key' => 'new', 'visit_no' => 1, 'entered_at' => $now];
                    $members[] = ['group_id' => $group['id'], 'instance_id' => $row->id, 'joined_at' => $now, 'enrollment_revision' => 2];
                }
                DB::table(Tables::name('active_process_slots'))->insert($slots);
                DB::table(Tables::name('stage_visits'))->insert($visits);
                DB::table(Tables::name('process_group_members'))->insert($members);
                $instanceIds = $rows->pluck('id')->all();
                $memberSlots = [];
                $assignments = [];
                foreach (DB::table(Tables::name('process_group_members'))->where('group_id', $group['id'])->whereIn('instance_id', $instanceIds)->get(['id', 'instance_id']) as $member) {
                    $memberSlots[] = ['group_id' => $group['id'], 'instance_id' => $member->instance_id, 'member_id' => $member->id];
                }
                foreach (DB::table(Tables::name('stage_visits'))->whereIn('instance_id', $instanceIds)->get(['id', 'instance_id']) as $visit) {
                    $assignments[] = ['scope_key' => 'one', 'instance_id' => $visit->instance_id, 'actor_ref' => 'a', 'kind' => 'primary', 'active_from' => $now,
                        'assigned_by' => 'a', 'reason_code' => 'synthetic_import', 'visit_id' => $visit->id];
                }
                DB::table(Tables::name('active_group_members'))->insert($memberSlots);
                DB::table(Tables::name('instance_assignments'))->insert($assignments);
                $assignmentSlots = [];
                foreach (DB::table(Tables::name('instance_assignments'))->whereIn('instance_id', $instanceIds)->get(['id', 'instance_id']) as $assignment) {
                    $assignmentSlots[] = ['instance_id' => $assignment->instance_id, 'slot_key' => 'primary', 'actor_ref' => 'a', 'assignment_id' => $assignment->id];
                }
                DB::table(Tables::name('assignment_slots'))->insert($assignmentSlots);
            });
            if (($offset + 1000) % 10000 === 0) {
                fwrite(STDERR, 'seed '.$prefix.' '.min($count, $offset + 1000).'/'.$count."\n");
            }
        }
        DB::table(Tables::name('process_groups'))->where('id', $group['id'])->update(['version' => 2]);
    }

    public function test_bounded_preview_materialization_and_processing_for_10k_and_100k(): void
    {
        Schema::table('host_subjects', fn (Blueprint $t) => $t->index(['scope_key', 'type', 'id'], 'host_subject_scale_lookup'));
        [$large, , $pipeline] = $this->campaignFixture(0);
        $small = $this->groupService()->register($this->manager(), 'crm.campaign', 'campaign-1', $large['policy'], 'small-scale-group');
        $this->seedScaleCohort($small, $pipeline, 10000, 'small');
        $this->seedScaleCohort($large, $pipeline, 100000, 'large');
        $this->closeCampaign();
        $full = getenv('TM_SCALE_FULL') === '1';
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $results = ['database' => 'tm_scale_p3_2c6b9e', 'engine' => DB::selectOne('SELECT version() AS value')->value, 'full_processing_requested' => $full,
            'fixture' => 'synthetic bulk import with real visits, assignments, slots, membership and host ACL; no real business data', 'profiles' => []];
        foreach ([[10000, $small], [100000, $large]] as [$count, $group]) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $start = hrtime(true);
            $before = $queries;
            $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
            $profile = ['members' => $count, 'preview_seconds' => (hrtime(true) - $start) / 1e9, 'preview_queries' => $queries - $before, 'preview_peak_bytes' => memory_get_peak_usage(true)];
            self::assertSame($count, $preview['total']);
            self::assertSame($count, $preview['counts']['failed']);
            self::assertLessThanOrEqual((int) ceil($count / 250) + 20, $profile['preview_queries']);
            self::assertLessThanOrEqual(128 * 1024 * 1024, $profile['preview_peak_bytes']);
            memory_reset_peak_usage();
            $start = hrtime(true);
            $before = $queries;
            $run = $this->groupService()->executeClosure($this->manager(), $preview, 'scale-'.$count);
            $profile += ['materialize_seconds' => (hrtime(true) - $start) / 1e9, 'materialize_queries' => $queries - $before, 'materialize_peak_bytes' => memory_get_peak_usage(true)];
            self::assertSame($count, DB::table(Tables::name('group_operation_items'))->where('run_id', $run['id'])->count());
            self::assertLessThanOrEqual(128 * 1024 * 1024, $profile['materialize_peak_bytes']);
            $processed = 0;
            $durations = [];
            $start = hrtime(true);
            $before = $queries;
            memory_reset_peak_usage();
            do {
                $chunkStart = hrtime(true);
                $chunk = $this->groupService()->process($this->manager(), $run['run_key'], 250);
                $durations[] = (hrtime(true) - $chunkStart) / 1e9;
                $processed += $chunk['processed'];
                if ($processed % 1000 === 0 || $chunk['state'] === 'complete') {
                    $progress = ['members' => $count, 'processed' => $processed, 'state' => $chunk['state'], 'elapsed_seconds' => (hrtime(true) - $start) / 1e9];
                    file_put_contents(__DIR__.'/../.runtime/scale-progress.json', json_encode($progress, JSON_PRETTY_PRINT));
                    fwrite(STDERR, json_encode($progress)."\n");
                }
                if (! $full) {
                    break;
                }
                if ($chunk['processed'] === 0 && $chunk['state'] !== 'complete') {
                    throw new \RuntimeException('Scale worker made no progress');
                }
            } while ($chunk['state'] !== 'complete');
            sort($durations);
            $profile += ['processed' => $processed, 'state' => $chunk['state'], 'processing_seconds' => (hrtime(true) - $start) / 1e9,
                'processing_queries' => $queries - $before, 'processing_peak_bytes' => memory_get_peak_usage(true), 'chunk_p95_seconds' => $durations[max(0, (int) ceil(count($durations) * .95) - 1)]];
            self::assertLessThanOrEqual(128 * 1024 * 1024, $profile['processing_peak_bytes']);
            if ($full) {
                self::assertSame($count, $processed);
                self::assertSame(['failed' => $count], $this->groupService()->report($this->manager(), $run['run_key'])['counts']);
            } else {
                self::assertSame(250, $processed);
                self::assertSame('processing', $chunk['state']);
            }
            $results['profiles'][] = $profile;
            file_put_contents(__DIR__.'/../docs/phase3-scale-postgres.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}
