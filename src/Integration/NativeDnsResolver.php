<?php

namespace Karnoweb\TaskManager\Integration;

use Karnoweb\TaskManager\Contracts\DnsResolver;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class NativeDnsResolver implements DnsResolver
{
    public function addresses(string $host):array{if(filter_var($host,FILTER_VALIDATE_IP)){return [$host];}$records=dns_get_record($host,DNS_A|DNS_AAAA);if($records===false){throw new DomainError('webhook_dns_failed');}$result=[];foreach($records as $record){if(isset($record['ip'])){$result[]=$record['ip'];}if(isset($record['ipv6'])){$result[]=$record['ipv6'];}}return array_values(array_unique($result));}
}
