<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\DnsResolver;
use Karnoweb\TaskManager\Contracts\SecretVault;
use Karnoweb\TaskManager\Contracts\WebhookTransport;
use Karnoweb\TaskManager\Tests\Support\WebhookFixture;

abstract class EnterpriseProcessTestCase extends GroupTestCase
{
    protected WebhookFixture $network;
    protected function setUp():void{parent::setUp();$this->network=new WebhookFixture();foreach([DnsResolver::class,SecretVault::class,WebhookTransport::class] as $contract){$this->app->instance($contract,$this->network);}}
    protected function manager(string $actor='a',string $scope='one'):ExecutionContext{$base=parent::manager($actor,$scope);return new ExecutionContext($scope,$actor,'enterprise-process',capabilities:[...$base->capabilities,'automation.manage','subscription.manage','webhook.manage','export.create','report.view']);}
    protected function worker():ExecutionContext{$base=$this->manager();return new ExecutionContext('one','a','enterprise-process-worker',true,$base->capabilities);}
}
