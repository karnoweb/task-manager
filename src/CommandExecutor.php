<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class CommandExecutor
{
    public function __construct(private readonly Connection $db)
    {
    }

    private function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonical($value);
            }
        }

        return $payload;
    }

    public function execute(ExecutionContext $context, string $operation, array $payload, callable $execute, callable $authorizeReplay): array
    {
        if (trim($operation) === '' || strlen($operation) > 120) {
            throw new DomainError('operation_invalid');
        }
        $hash = hash('sha256', json_encode([$context->actor, $this->canonical($payload)], JSON_THROW_ON_ERROR));

        return $this->db->transaction(function () use ($context, $operation, $hash, $execute, $authorizeReplay) {
            $reserved = $this->db->table('tm_command_journal')->insertOrIgnore(['scope_key' => $context->scope,
                'operation_id' => $operation, 'actor_ref' => $context->actor, 'payload_hash' => $hash, 'result' => '']);
            $prior = $this->db->table('tm_command_journal')->where('scope_key', $context->scope)->where('operation_id', $operation)->lockForUpdate()->first();
            if (! $reserved) {
                if ($prior->payload_hash !== $hash) {
                    throw new DomainError('idempotency_key_conflict');
                }
                $result = json_decode($prior->result, true, flags: JSON_THROW_ON_ERROR);
                $authorizeReplay($result);

                return $result;
            }
            $result = $execute();
            $this->db->table('tm_command_journal')->where('scope_key', $context->scope)->where('operation_id', $operation)
                ->update(['status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }
}
