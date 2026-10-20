<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Support\Str;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class WebhookSignature
{
    public static function sign(string $secret,string $timestamp,string $eventId,string $body):string{return 'sha256='.hash_hmac('sha256',$timestamp.'.'.$eventId.'.'.$body,$secret);}
    public static function verify(string $secret,string $timestamp,string $eventId,string $body,string $signature,\DateTimeImmutable $now):void
    {
        if(strlen($body)>262144||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs($now->getTimestamp()-(int)$timestamp)>300||!Str::isUuid($eventId)||$eventId!==strtolower($eventId)||!hash_equals(self::sign($secret,$timestamp,$eventId,$body),$signature)){throw new DomainError('webhook_signature_invalid');}
    }
}
