<?php

namespace App\TaskManager;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\LocalSubjectRouter;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class TicketDepartmentRouter implements LocalSubjectRouter
{
    /** @param array<string,int> $destinations Trusted server map: engine pipeline UUID => host department ID. */
    public function __construct(private Connection $db, private TicketSubject $tickets, private TicketWrites $writes, private array $destinations, private ?string $assignee = 'preserve')
    {
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function spawn(ExecutionContext $context, array $origin, string $operation): array
    {
        throw new DomainError('destination_not_accepted');
    }

    public function move(ExecutionContext $context, array $origin, string $targetPipeline, string $operation): void
    {
        if ($origin['subject_type'] !== 'support.ticket' || ! isset($this->destinations[$targetPipeline]) || ! $this->tickets->query($context, 'edit')->where($this->tickets->idColumn(), $origin['subject_id'])->exists()) {
            throw new DomainError('destination_not_accepted');
        }
        $this->writes->moveDepartment($context, (int) $origin['subject_id'], $this->destinations[$targetPipeline], $this->assignee);
    }
}
