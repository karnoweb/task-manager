<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM039Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a');
        $b = $this->todo('b');
        $link = $this->link($a, $b, 'relates_to');
        self::assertSame('done', $this->service()->mutate($this->manager(), $b['public_key'], 2, 'complete-b', 'complete')['state']);
        $b = $this->service()->get($this->manager(), $b['public_key']);
        $this->service()->share($this->manager(), $b['public_key'], $b['version'], 'share-b', 'b');
        self::assertSame(0, $this->dependencies()->links($this->manager('b'), $b['public_key'])['total']);
        $this->error('not_found', fn () => $this->dependencies()->get($this->manager('b'), $link['public_key']));
        self::assertSame(1, $this->dependencies()->links($this->manager(), $b['public_key'])['total']);
    }

    public static function kinds(): array
    {
        return [['FS'], ['SS'], ['FF'], ['SF']];
    }
}
