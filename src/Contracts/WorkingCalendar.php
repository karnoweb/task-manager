<?php

namespace Karnoweb\TaskManager\Contracts;

interface WorkingCalendar
{
    public function addSeconds(\DateTimeImmutable $start, int $seconds): \DateTimeImmutable;

    public function secondsBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int;
}
