<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Enterprise\PresetService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM054Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $presets = $this->app->make(PresetService::class);
        $preset = $presets->create($this->manager(), 'Default', ['title' => 'Todo', 'checklists' => [['title' => 'Checks', 'items' => ['One', 'Two']]]], 'preset');
        $task = $presets->instantiate($this->manager(), $preset['preset_key'], 'instantiate');
        self::assertSame($task, $presets->instantiate($this->manager(), $preset['preset_key'], 'instantiate'));
        self::assertSame(2, DB::table('tm_checklist_items')->count());
        self::assertSame(0, DB::table('tm_checklist_items')->whereNotNull('assignee_ref')->count());
        $this->error('preset_invalid', fn () => $presets->create($this->manager(), 'Bad', ['title' => 'Bad', 'members' => ['other']], 'bad'));
        $this->error('not_found', fn () => $presets->get($this->manager('b'), $preset['preset_key']));
    }
}
