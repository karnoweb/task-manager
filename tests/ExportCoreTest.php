<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ExportStorage;
use Karnoweb\TaskManager\Enterprise\ExportService;

final class ExportCoreTest extends PhaseFiveTestCase
{
    private function exports(): ExportService
    {
        return $this->app->make(ExportService::class);
    }

    private function worker(string $actor = 'a'): ExecutionContext
    {
        $base = $this->manager($actor);

        return new ExecutionContext('one', $actor, 'export-worker', true, $base->capabilities);
    }

    public function test_csv_json_streaming_filters_permissions_and_escapes_formula_cells(): void
    {
        $a = $this->todo('=HYPERLINK("bad")');
        $this->service()->create($this->manager('b'), 'PRIVATE', 'private');
        $csv = implode('', iterator_to_array($this->exports()->stream($this->manager(), ['format' => 'csv', 'columns' => ['title']])));
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringNotContainsString('PRIVATE', $csv);
        $json = implode('', iterator_to_array($this->exports()->stream($this->manager(), ['format' => 'json', 'columns' => ['public_key', 'title']])));
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $data);
        self::assertSame($a['public_key'], $data[0]['public_key']);
    }

    public function test_async_private_file_download_revalidates_revocation_and_expiry(): void
    {
        $task = $this->todo('secret');
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b');
        $job = $this->exports()->enqueue($this->manager('b'), ['format' => 'json', 'columns' => ['title']], 'enqueue');
        $ready = $this->exports()->build($this->worker('b'), $job['export_key'], 1);
        self::assertSame('ready', $ready['state']);
        self::assertArrayNotHasKey('file_ref', $ready);
        $stream = $this->exports()->download($this->manager('b'), $job['export_key']);
        self::assertSame([['title' => 'secret']], json_decode(stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR));
        fclose($stream);
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        $this->error('export_permission_revoked', fn () => $this->exports()->download($this->manager('b'), $job['export_key']));
        $this->error('not_found', fn () => $this->exports()->download($this->manager(), $job['export_key']));
        $reference = DB::table('tm_exports')->where('id', $job['id'])->value('file_ref');
        $this->app->make(ExportStorage::class)->delete($this->manager('b'), $reference);
    }
}
