<?php

namespace Karnoweb\TaskManager\Integration;

use Karnoweb\TaskManager\Contracts\WebhookTransport;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class CurlWebhookTransport implements WebhookTransport
{
    public function post(WebhookTarget $target,string $body,array $headers):array
    {
        if(!extension_loaded('curl')||!WebhookUrlPolicy::publicAddress($target->address)){throw new DomainError('webhook_transport_unavailable');}$handle=curl_init($target->url);$count=0;$ip=str_contains($target->address,':')?'['.$target->address.']':$target->address;
        $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>10000,CURLOPT_RESOLVE=>[$target->host.':443:'.$ip],CURLOPT_WRITEFUNCTION=>function($curl,string $bytes)use(&$count){$count+=strlen($bytes);return $count>65536?0:strlen($bytes);}];
        if(defined('CURLOPT_PROTOCOLS_STR')){$options[CURLOPT_PROTOCOLS_STR]='https';}else{$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;}
        curl_setopt_array($handle,$options);try{$ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$remote=curl_getinfo($handle,CURLINFO_PRIMARY_IP);if($ok===false||inet_pton($remote)!==inet_pton($target->address)){throw new DomainError($count>65536?'webhook_response_limit':'webhook_transport_failed');}return ['status'=>$status,'redirect'=>$status>=300&&$status<400,'bytes'=>$count];}finally{curl_close($handle);}
    }
}
