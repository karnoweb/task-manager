<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Integration\WebhookTarget;

interface WebhookTransport { /** Must pin the validated address, verify TLS, disable proxy/redirect and bound response/time. */ public function post(WebhookTarget $target,string $body,array $headers):array; }
