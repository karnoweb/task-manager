<?php

declare(strict_types=1);

$root = dirname(__DIR__).'/tests';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$pattern = '/\$this->expectExceptionMessage\(\'([a-z][a-z0-9_]*)\'\);\s*\n(\s*)(\$.*?);/s';

foreach ($iterator as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if (! is_string($contents) || ! str_contains($contents, 'expectExceptionMessage')) {
        continue;
    }
    $updated = preg_replace_callback($pattern, static function (array $m): string {
        return $m[2].'$this->error(\''.$m[1].'\', fn () => '.$m[3].');';
    }, $contents);
    $updated = preg_replace('/\$this->expectException\(DomainError::class\);\s*\n\s*\$this->error\(/', '$this->error(', $updated) ?? $updated;
    if ($updated !== $contents) {
        file_put_contents($path, $updated);
        echo $path, PHP_EOL;
    }
}
