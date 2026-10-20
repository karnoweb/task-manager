<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;


use Karnoweb\TaskManager\Tests\EnterpriseScaleTestCase;

final class TM133Test extends EnterpriseScaleTestCase
{
    public function test_scenario():void
    {$this->verifyPaging(100000,true);}

}
