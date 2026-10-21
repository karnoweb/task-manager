<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TemplateService;

final class TemplateCoreTest extends AgileTestCase
{
    public function test_template_pins_workflow_and_template_revisions_with_retry_safe_real_project_creation(): void
    {
        $revision = $this->sales();
        $templates = $this->app->make(TemplateService::class);
        $definition = ['workflow_revision_id' => $revision['id'], 'max_depth' => 8, 'tasks' => [
            ['alias' => 'child', 'title' => 'Child', 'parent' => 'epic', 'story_points' => '3'], ['alias' => 'epic', 'title' => 'Epic', 'type' => 'epic']]];
        $template = $templates->publish($this->manager(), null, 0, 'Original', $definition, 'template');
        $changed = $this->definition();
        $changed['states']['other'] = ['terminal' => 'none'];
        $this->workflows()->publish($this->manager(), 'sales', 1, 'workflow-republish', $changed);
        $definition['tasks'][0]['title'] = 'Changed';
        $templates->publish($this->manager(), $template['template_key'], 1, 'Second', $definition, 'republish-template');
        $created = $templates->instantiate($this->manager(), $template['template_key'], 1, 'ONE', 'One', 'instantiate');
        self::assertSame($revision['id'], $created['project']['workflow_revision_id']);
        self::assertSame(8, $created['project']['hierarchy_max_depth']);
        $child = $this->service()->get($this->manager(), $created['tasks']['child']);
        $parent = $this->service()->get($this->manager(), $created['tasks']['epic']);
        self::assertSame('Child', $child['title']);
        self::assertSame($parent['id'], $child['parent_task_id']);
        self::assertSame('3.00', $this->app->make(ReportingService::class)->totals($this->manager(), $created['project']['project_key'])['points']);
        self::assertSame($created['project']['id'], $templates->instantiate($this->manager(), $template['template_key'], 1, 'ONE', 'One', 'instantiate')['project']['id']);
        self::assertSame(2, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_project_template_instances')->count());
        $this->error('not_found', fn () => $templates->get($this->manager('b'), $template['template_key']));
        $this->error('not_found', fn () => $templates->get($this->manager('a', 'two'), $template['template_key']));
    }

    public function test_invalid_template_graph_and_foreign_workflow_never_create_partial_templates(): void
    {
        $revision = $this->sales();
        $templates = $this->app->make(TemplateService::class);
        $this->error('task_hierarchy_cycle', fn () => $templates->publish($this->manager(), null, 0, 'Cycle', ['workflow_revision_id' => $revision['id'], 'tasks' => [['alias' => 'a', 'title' => 'A', 'parent' => 'b'], ['alias' => 'b', 'title' => 'B', 'parent' => 'a']]], 'cycle'));
        $this->error('not_found', fn () => $templates->publish($this->manager('a', 'two'), null, 0, 'Foreign', ['workflow_revision_id' => $revision['id']], 'foreign'));
        self::assertSame(0, DB::table('tm_project_templates')->count());
    }
}
