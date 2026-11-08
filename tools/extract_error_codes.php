<?php

declare(strict_types=1);

$root = dirname(__DIR__).'/src';
$codes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    preg_match_all("/new DomainError\\(\\s*'([a-z0-9_]+)'/", $contents, $matches);
    foreach ($matches[1] as $code) {
        $codes[$code] = true;
    }
}
ksort($codes);
foreach (array_keys($codes) as $code) {
    echo $code, PHP_EOL;
}
