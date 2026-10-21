<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\DnsResolver;
use Karnoweb\TaskManager\Contracts\SecretVault;
use Karnoweb\TaskManager\Contracts\WebhookTransport;
use Karnoweb\TaskManager\Integration\WebhookService;
use Karnoweb\TaskManager\Tests\Support\WebhookFixture;

abstract class WebhookTestCase extends PhaseFiveTestCase
{
    protected WebhookFixture $network;

    protected function setUp(): void
    {
        parent::setUp();
        $this->network = new WebhookFixture;
        foreach ([DnsResolver::class, SecretVault::class, WebhookTransport::class] as $contract) {
            $this->app->instance($contract, $this->network);
        }
    }

    protected function webhooks(): WebhookService
    {
        return $this->app->make(WebhookService::class);
    }

    protected function webhookWorker(): ExecutionContext
    {
        $base = $this->manager();

        return new ExecutionContext('one', 'a', 'webhook-worker', true, $base->capabilities);
    }
}
