<?php
declare(strict_types=1);

require_once __DIR__ . '/config.local.php';

$recoveryConfigFile = __DIR__ . '/recovery.local.php';
if (is_file($recoveryConfigFile)) {
    require_once $recoveryConfigFile;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

require_once __DIR__ . '/security_guard.php';
site_ip_guard_enforce(db());
