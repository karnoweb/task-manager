<?php

use PHPUnit\TextUI\Application;

$root = dirname(__DIR__);
$profile = $argv[1] ?? '';
if (! in_array($profile, ['task-only', 'kanban-only', 'calendar-only', 'all'], true)) {
    throw new InvalidArgumentException('Unknown profile');
}
$autoload = $root.'/.runtime/phase6-hosts/'.$profile.'/vendor/autoload.php';
require $autoload;
putenv('TM_PHASE6_INSTALL_PROFILE='.$profile);
$reports = $root.'/.runtime/test-results';
if (! is_dir($reports)) {
    mkdir($reports, 0777, true);
}
$arguments = ['phpunit', '--no-configuration', '--bootstrap', $autoload, '--log-junit', $reports.'/phase6-install-'.$profile.'.xml', $root.'/tests/HostProfiles/InstallationProbe.php'];
exit((new Application)->run($arguments));
