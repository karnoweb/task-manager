<?php

namespace Karnoweb\TaskManager\Workflow;

use Karnoweb\TaskManager\Exceptions\DomainError;

final class Definition
{
    public static function validate(array $definition): void
    {
        $states = $definition['states'] ?? null; $edges = $definition['transitions'] ?? null;
        $initial = $definition['initial'] ?? null;
        if (!is_array($states) || !$states || array_is_list($states) || !is_array($edges) || !is_string($initial) || !isset($states[$initial])) { throw new DomainError('workflow_invalid'); }
        foreach ($states as $key => $state) {
            self::key($key);
            if (!is_array($state) || !in_array($state['terminal'] ?? 'none', ['none', 'success', 'failure', 'cancelled'], true)) { throw new DomainError('state_invalid'); }
        }
        foreach ($edges as $key => $edge) {
            self::key($key);
            if (!is_array($edge) || !is_string($edge['from'] ?? null) || !is_string($edge['to'] ?? null)
                || !isset($states[$edge['from']], $states[$edge['to']])) { throw new DomainError('transition_invalid'); }
            if (isset($edge['reason_required']) && !is_bool($edge['reason_required'])) { throw new DomainError('transition_invalid'); }
            if (isset($edge['conditions']) && (!is_array($edge['conditions']) || !array_is_list($edge['conditions']))) { throw new DomainError('condition_invalid'); }
            foreach (['required_fields', 'roles', 'guards', 'reason_codes'] as $field) {
                if (isset($edge[$field]) && (!is_array($edge[$field]) || !array_is_list($edge[$field]))) { throw new DomainError('transition_invalid'); }
                foreach ($edge[$field] ?? [] as $value) {
                    if (!is_string($value)) { throw new DomainError('transition_invalid'); }
                    if ($field === 'reason_codes') { self::key($value); }
                }
            }
            $needsReason = ($edge['reason_required'] ?? false) || in_array($states[$edge['to']]['terminal'] ?? 'none', ['failure', 'cancelled'], true);
            if ($needsReason && empty($edge['reason_codes'])) { throw new DomainError('reason_catalog_required'); }
            foreach ($edge['conditions'] ?? [] as $condition) {
                if (!is_array($condition) || !is_string($condition['field'] ?? null) || !array_key_exists('value', $condition)
                    || !in_array($condition['operator'] ?? '', ['eq', 'neq', 'gte', 'in'], true)) { throw new DomainError('condition_invalid'); }
            }
        }
    }
    public static function key(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $value)) { throw new DomainError('key_invalid'); }
    }
    public static function decode(string $json): array
    {
        $definition = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        // Upgrade the original personal blueprint's list representation in memory.
        if (isset($definition['states']) && array_is_list($definition['states'])) {
            return ['initial' => 'pending', 'states' => ['pending' => ['terminal' => 'none'], 'done' => ['terminal' => 'success']],
                'transitions' => ['complete' => ['from' => 'pending', 'to' => 'done'], 'reopen' => ['from' => 'done', 'to' => 'pending']]];
        }
        return $definition;
    }
}
