<?php

declare(strict_types=1);

$hostAutoload = getenv('TM_PHASE6_HOST_AUTOLOAD') ?: dirname(__DIR__).'/.runtime/phase6-hosts/all/vendor/autoload.php';
if (! is_file($hostAutoload)) {
    throw new RuntimeException('Prepare the phase-six all host or set TM_PHASE6_HOST_AUTOLOAD before analysis.');
}
require_once $hostAutoload;
