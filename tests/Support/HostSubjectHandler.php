<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class HostSubjectHandler implements SubjectHandler
{
    public function __construct(private readonly Connection $db, private readonly string $alias)
    {
    }

    public function query(ExecutionContext $context, string $capability): Builder
    {
        return $this->db->table('host_subjects')->where('type', $this->alias)->whereExists(function (Builder $q) use ($context, $capability) {
            $q->selectRaw('1')->from('host_permissions')->whereColumn('subject_id', 'host_subjects.id')->where('actor', $context->actor);
            if ($capability === 'edit') {
                $q->where('can_edit', true);
            }
        });
    }

    public function idColumn(): string
    {
        return 'id';
    }

    public function scopeColumn(): string
    {
        return 'scope_key';
    }

    public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void
    {
        $subject = $this->query($context, 'edit')->where('scope_key', $context->scope)->where('id', $subjectId)->first();
        if (! $subject) {
            throw new DomainError('not_found');
        }
        if ($subject->legacy_status !== null) {
            throw new DomainError('host_state_conflict');
        }
        if ($subject->veto) {
            // Verify a same-DB host write also rolls back if a later guard vetoes.
            $this->db->table('host_subjects')->where('id', $subjectId)->increment('guard_touches');
            throw new DomainError('host_guard_veto');
        }
    }
}
