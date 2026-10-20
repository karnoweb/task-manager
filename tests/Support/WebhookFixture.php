<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\TaskManager\Contracts\DnsResolver;
use Karnoweb\TaskManager\Contracts\SecretVault;
use Karnoweb\TaskManager\Contracts\WebhookTransport;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Integration\WebhookTarget;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class WebhookFixture implements DnsResolver,SecretVault,WebhookTransport
{
    public array $addresses=['8.8.8.8'];public array $requests=[];public array $responses=[]; public ?\Closure $onResolve=null;
    public function addresses(string $host):array{if($this->onResolve!==null){$hook=$this->onResolve;$this->onResolve=null;$hook();}return $this->addresses;}
    public function resolve(ExecutionContext $context,string $reference):string{if($reference!=='fixture-secret'){throw new DomainError('webhook_secret_unavailable');}return str_repeat('s',32);}
    public function post(WebhookTarget $target,string $body,array $headers):array{$this->requests[]=compact('target','body','headers');$response=array_shift($this->responses)??['status'=>204];if($response instanceof \Throwable){throw $response;}return $response;}
}
