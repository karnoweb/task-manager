<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ClassConst\SplitGroupedClassConstantsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/database', __DIR__.'/tests', __DIR__.'/tools'])
    ->withPhpVersion(80300)
    ->withRules([SplitGroupedClassConstantsRector::class])
    ->withCache(__DIR__.'/.runtime/rector')
    ->withoutParallel();
