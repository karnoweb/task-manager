<?php

namespace Karnoweb\TaskManager\UI;

use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class BoundCursor
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) { throw new DomainError('cursor_key_required'); }
    }

    public function encode(array $binding, array $position): string
    {
        $body = rtrim(strtr(base64_encode(json_encode(['binding' => hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR)), 'position' => $position, 'expires' => time() + 900], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $body.'.'.hash_hmac('sha256', $body, $this->secret);
    }

    public function decode(?string $token, array $binding): array
    {
        if ($token === null) { return []; }
        if (strlen($token) > 2048) { throw new DomainError('cursor_invalid'); }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $this->secret), $parts[1])) { throw new DomainError('cursor_invalid'); }
        try { $data = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new DomainError('cursor_invalid'); }
        if (($data['expires'] ?? 0) < time() || ($data['binding'] ?? '') !== hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR)) || !is_array($data['position'] ?? null)) { throw new DomainError('cursor_invalid'); }
        return $data['position'];
    }
}
