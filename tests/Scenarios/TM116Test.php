<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM116Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $field = $this->fields()->create($this->manager(), 'Cost', 'number', [], null, 'field');
        $date = $this->fields()->create($this->manager(), 'Date', 'date', [], null, 'date-field');
        $a = $this->todo('a');
        $b = $this->todo('b');
        $aValue = $this->fields()->set($this->manager(), $a['public_key'], 1, $field['field_key'], 1, '10.5', 'a-cost');
        $this->fields()->set($this->manager(), $b['public_key'], 1, $field['field_key'], 1, '2', 'b-cost');
        $this->fields()->set($this->manager(), $a['public_key'], $aValue['task_version'], $date['field_key'], 1, '2026-10-25', 'a-date');
        $result = $this->queries()->tasks($this->manager(), [], 'custom:'.$field['field_key']);
        self::assertSame([$b['public_key'], $a['public_key']], array_column($result['items'], 'public_key'));
        self::assertSame(1, $this->queries()->tasks($this->manager(), ['custom' => [['field' => $field['field_key'], 'operator' => '>', 'value' => '5']]])['total']);
        self::assertSame(1, $this->queries()->tasks($this->manager(), ['custom' => [['field' => $date['field_key'], 'operator' => '<', 'value' => '2026-11-01']]])['total']);
        $this->error('custom_number_invalid', fn () => $this->fields()->set($this->manager(), $a['public_key'], 3, $field['field_key'], 1, 'NaN', 'bad'));
        $this->fields()->archive($this->manager(), $field['field_key'], 1, true, 'archive');
        self::assertSame('10.500000', $this->fields()->value($this->manager(), $a['public_key'], $field['field_key'])['value']);
        self::assertCount(1, $this->fields()->forms($this->manager()));
        $this->error('not_found', fn () => $this->fields()->value($this->manager('b'), $a['public_key'], $field['field_key']));
    }

    private function fields(): CustomFieldService
    {
        return $this->app->make(CustomFieldService::class);
    }

    private function queries(): QueryService
    {
        return $this->app->make(QueryService::class);
    }
}
