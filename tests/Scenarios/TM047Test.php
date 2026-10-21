<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM047Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task');
        $other = $this->todo('other');
        $this->storage->put('opaque-file', 'one', 'a', 'actual file bytes');
        $file = $this->attachments()->attach($this->manager(), $task['public_key'], 'opaque-file', 'attach');
        $ticket = $this->attachments()->issueTicket($this->manager(), $task['public_key'], $file['public_key']);
        $stream = $this->attachments()->download($this->manager(), $task['public_key'], $file['public_key'], $ticket['token']);
        self::assertSame('actual file bytes', stream_get_contents($stream));
        fclose($stream);
        $this->error('not_found', fn () => $this->attachments()->download($this->manager(), $other['public_key'], $file['public_key'], $ticket['token']));
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b');
        $bTicket = $this->attachments()->issueTicket($this->manager('b'), $task['public_key'], $file['public_key']);
        $this->error('not_found', fn () => $this->attachments()->download($this->manager(), $task['public_key'], $file['public_key'], $bTicket['token']));
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        $this->error('not_found', fn () => $this->attachments()->download($this->manager('b'), $task['public_key'], $file['public_key'], $bTicket['token']));
        $this->clock->advance('+121 seconds');
        $this->error('not_found', fn () => $this->attachments()->download($this->manager(), $task['public_key'], $file['public_key'], $ticket['token']));
        self::assertStringNotContainsString($ticket['token'], json_encode(DB::table('tm_attachment_tickets')->get()->all()));
        self::assertStringNotContainsString($ticket['token'], json_encode(DB::table('tm_command_journal')->get()->all()));
    }
}
