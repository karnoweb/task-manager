<?php

namespace Karnoweb\TaskManager\Integration;

final readonly class WebhookTarget { public function __construct(public string $url,public string $host,public string $address,public int $port=443){} }
