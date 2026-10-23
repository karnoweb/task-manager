<?php

namespace Karnoweb\TaskManager\Tests;

use App\Console\Commands\TaskManagerExample;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

final class DocumentationExamplesTest extends PhaseFiveTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__).'/examples/Console/TaskManagerExample.php';
        $this->app->make(Kernel::class)->registerCommand(new TaskManagerExample);
    }

    public function test_documented_todo_command_runs_without_project_or_ui(): void
    {
        $this->artisan('task-manager:example', ['scenario' => 'todo'])->assertSuccessful();
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertNull(DB::table('tm_tasks')->value('project_id'));
        self::assertSame('pending', $this->service()->get($this->manager(), DB::table('tm_tasks')->value('public_key'))['state']);
    }

    public function test_documented_workflow_project_pipeline_command_runs_without_ui(): void
    {
        $this->artisan('task-manager:example', ['scenario' => 'workflow'])->assertSuccessful();
        self::assertSame(1, DB::table('tm_projects')->count());
        self::assertSame(1, DB::table('tm_pipelines')->count());
        self::assertSame('review', DB::table('tm_workflow_instances')->where('workflow_key', '!=', 'personal')->value('current_state_key'));
    }

    public function test_documented_personal_reminder_command_creates_no_task(): void
    {
        $this->artisan('task-manager:example', ['scenario' => 'reminder'])->assertSuccessful();
        self::assertSame(1, DB::table('tm_reminders')->count());
        self::assertSame(0, DB::table('tm_tasks')->count());
    }
}
