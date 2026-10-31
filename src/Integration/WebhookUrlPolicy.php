<?php

namespace Karnoweb\TaskManager\Integration;

use Karnoweb\TaskManager\Contracts\DnsResolver;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class WebhookUrlPolicy
{
    public function __construct(private readonly DnsResolver $dns, private readonly array $allowedHosts = [])
    {
    }

    private static function cidr(string $address, string $network, int $bits): bool
    {
        $a = inet_pton($address);
        $b = inet_pton($network);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }$bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        return substr($a, 0, $bytes) === substr($b, 0, $bytes) && ($rest === 0 || ((ord($a[$bytes]) & (255 << (8 - $rest))) === (ord($b[$bytes]) & (255 << (8 - $rest)))));
    }

    public static function publicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network,$bits]) {
                if (self::cidr($address, $network, $bits)) {
                    return false;
                }
            }

            return true;
        }
        if (! self::cidr($address, '2000::', 3)) {
            return false;
        }foreach ([['2001:db8::', 32], ['2001::', 32], ['2002::', 16], ['2001:10::', 28], ['2001:20::', 28], ['2001:2::', 48]] as [$network,$bits]) {
            if (self::cidr($address, $network, $bits)) {
                return false;
            }
        }

        return true;
    }

    public function validate(string $url): WebhookTarget
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new DomainError('webhook_url_invalid');
        }$parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
            throw new DomainError('webhook_url_invalid');
        }
        $host = strtolower($parts['host'] ?? '');
        if (! $host || str_ends_with($host, '.') || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($host, '.') || ($this->allowedHosts && ! in_array($host, $this->allowedHosts, true))) {
            throw new DomainError('webhook_host_invalid');
        }
        $addresses = $this->dns->addresses($host);
        if (! $addresses || count($addresses) > 64) {
            throw new DomainError('webhook_dns_failed');
        }foreach ($addresses as $address) {
            if (! is_string($address) || ! self::publicAddress($address)) {
                throw new DomainError('webhook_private_address');
            }
        }

        return new WebhookTarget($url, $host, $addresses[0]);
    }
}
