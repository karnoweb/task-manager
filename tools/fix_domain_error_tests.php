<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php fix_domain_error_tests.php <file|directory>\n");
    exit(1);
}

$targets = [];
$argument = $argv[1];
if (is_file($argument)) {
    $targets[] = $argument;
} else {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argument));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $targets[] = $file->getPathname();
        }
    }
}

$pattern = '/self::assertSame\(\'([a-z][a-z0-9_]*)\',\s*(\$(?:error|e))->getMessage\(\)\)/';
$patternVar = '/self::assertSame\(\$code,\s*(\$(?:error|e))->getMessage\(\)\)/';
$concurrent = '/(\[\'error\'\s*=>\s*)(\$error)->getMessage\(\)/';

foreach ($targets as $path) {
    $contents = file_get_contents($path);
    if ($contents === false || $contents === '') {
        continue;
    }
    $updated = preg_replace($pattern, 'self::assertSame(\'$1\', $2->code)', $contents);
    if (! is_string($updated)) {
        continue;
    }
    $updated = preg_replace($patternVar, 'self::assertSame($code, $1->code)', $updated);
    $updated = preg_replace($concurrent, '$1$2->code', $updated);
    if ($updated !== $contents) {
        file_put_contents($path, $updated);
        echo $path, PHP_EOL;
    }
}
