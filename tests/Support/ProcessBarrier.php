<?php

namespace Karnoweb\TaskManager\Tests\Support;

final class ProcessBarrier
{
    public static function run(array $commands): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if (!$server) { throw new \RuntimeException($message); }
        $address = 'tcp://'.stream_socket_get_name($server, false); $workers = []; $clients = [];
        try {
            foreach ($commands as $command) {
                $process = proc_open([PHP_BINARY, __DIR__.'/concurrent-workflow.php', $address, ...array_map(strval(...), $command)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) { throw new \RuntimeException('Worker failed to start'); }
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as $_) {
                $client = stream_socket_accept($server, 10); if (!$client) { throw new \RuntimeException('Worker barrier timed out'); }
                stream_set_timeout($client, 10); if (fgets($client) !== "ready\n") { throw new \RuntimeException('Worker barrier handshake failed'); }
                $clients[] = $client;
            }
            foreach ($clients as $client) { fwrite($client, "go\n"); fclose($client); }
            $results = [];
            foreach ($workers as $index => [$process, $pipes]) {
                fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process); unset($workers[$index]);
                if ($code !== 0) { throw new \RuntimeException('Worker failed: '.$errors); }
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($clients as $client) { if (is_resource($client)) { fclose($client); } }
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); }
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                if (is_resource($process)) { proc_close($process); }
            }
            fclose($server);
        }
    }
}
