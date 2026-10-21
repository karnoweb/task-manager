<?php

namespace Karnoweb\TaskManager\Tests;

final class EnterpriseScaleTest extends EnterpriseScaleTestCase
{
    public function test_ten_thousand_tasks_are_paged_in_sql_with_authorized_counts_and_bounded_queries(): void
    {
        $this->verifyPaging(10000);
    }

    public function test_hundred_thousand_task_instance_visit_rows_stay_bounded_and_permission_filtered(): void
    {
        $this->verifyPaging(100000, true);
    }
}
