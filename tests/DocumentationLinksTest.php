<?php

namespace Karnoweb\TaskManager\Tests;

use PHPUnit\Framework\TestCase;

final class DocumentationLinksTest extends TestCase
{
    public function test_all_current_markdown_links_resolve(): void
    {
        $root = dirname(__DIR__);
        $files = [$root.'/README.md'];
        foreach (['docs', 'tests/Scenarios'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $checked = 0;
        foreach ($files as $file) {
            preg_match_all('/\[[^\]]+\]\(([^)]+)\)/u', file_get_contents($file), $matches);
            foreach ($matches[1] as $link) {
                if (str_contains($link, ':') || str_starts_with($link, '#')) {
                    continue;
                }
                [$path, $fragment] = array_pad(explode('#', $link, 2), 2, null);
                $target = realpath(dirname($file).'/'.$path);
                self::assertNotFalse($target, $file.' -> '.$link);
                if ($fragment !== null && str_ends_with($target, '.md')) {
                    preg_match_all('/^#+\s+(.+)$/mu', file_get_contents($target), $headings);
                    $slugs = array_map(static function ($heading) {
                        $heading = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', mb_strtolower(strip_tags($heading)));

                        return preg_replace('/\s/u', '-', trim($heading));
                    }, $headings[1]);
                    self::assertContains($fragment, $slugs, $file.' -> '.$link);
                }
                $checked++;
            }
        }
        // The old threshold counted hundreds of intentionally removed duplicate guides.
        // Require the complete current catalog, then validate every discovered link above.
        foreach (glob($root.'/tests/Scenarios/Complete/*.md') as $scenario) {
            self::assertContains(realpath($scenario), array_map('realpath', $files));
        }
        self::assertGreaterThan(250, $checked);
    }
}
