<?php

declare(strict_types=1);

$profiles = [
    'mariadb' => ['mysql:host=127.0.0.1;port=3306;dbname=tm_acceptance_p3_72bd61', 'tm_p3_72bd61', 'local_fixture_72bd61'],
    'pgsql' => ['pgsql:host=127.0.0.1;port=55439;dbname=tm_acceptance', 'tm_test', ''],
];
$report = ['php' => PHP_VERSION, 'extensions' => get_loaded_extensions(), 'profiles' => []];
foreach ($profiles as $name => [$dsn, $user, $password]) {
    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $report['profiles'][$name] = ['connected' => true, 'version' => $pdo->query('SELECT version()')->fetchColumn()];
    } catch (Throwable $error) {
        $report['profiles'][$name] = ['connected' => false, 'error' => $error->getMessage()];
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
