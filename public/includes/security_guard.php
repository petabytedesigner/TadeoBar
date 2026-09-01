<?php
declare(strict_types=1);

const SITE_IP_GUARD_MAX_FAILED_LOGINS = 15;
const SITE_IP_GUARD_FAILURE_WINDOW_HOURS = 24;
const SITE_IP_GUARD_BLOCK_HOURS = 24;

function site_ip_guard_hash(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        $ip = 'unknown';
    }

    return hash('sha256', 'tadeo-security-ip|' . $ip);
}

function site_ip_guard_ensure_storage(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `security_ip_guard` (
            `ip_hash` char(64) NOT NULL,
            `failed_attempts` smallint unsigned NOT NULL DEFAULT 0,
            `window_started_at` datetime NOT NULL,
            `blocked_until` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ip_hash`),
            KEY `idx_security_ip_guard_blocked_until` (`blocked_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function site_ip_guard_blocked(PDO $pdo): bool
{
    site_ip_guard_ensure_storage($pdo);

    $stmt = $pdo->prepare(
        'SELECT blocked_until FROM security_ip_guard WHERE ip_hash = ? LIMIT 1'
    );
    $stmt->execute([site_ip_guard_hash()]);
    $blockedUntil = $stmt->fetchColumn();

    if ($blockedUntil === false || $blockedUntil === null || trim((string)$blockedUntil) === '') {
        return false;
    }

    $blockedTimestamp = strtotime((string)$blockedUntil);
    if ($blockedTimestamp !== false && $blockedTimestamp > time()) {
        return true;
    }

    $stmt = $pdo->prepare('DELETE FROM security_ip_guard WHERE ip_hash = ?');
    $stmt->execute([site_ip_guard_hash()]);

    return false;
}

function site_ip_guard_register_failed_login(PDO $pdo): bool
{
    site_ip_guard_ensure_storage($pdo);

    $ipHash = site_ip_guard_hash();
    $windowHours = SITE_IP_GUARD_FAILURE_WINDOW_HOURS;
    $blockHours = SITE_IP_GUARD_BLOCK_HOURS;

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT failed_attempts, window_started_at, blocked_until '
            . 'FROM security_ip_guard WHERE ip_hash = ? FOR UPDATE'
        );
        $stmt->execute([$ipHash]);
        $row = $stmt->fetch();

        if ($row === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_ip_guard (ip_hash, failed_attempts, window_started_at) '
                . 'VALUES (?, 1, NOW())'
            );
            $stmt->execute([$ipHash]);
            $pdo->commit();
            return false;
        }

        $blockedUntil = trim((string)($row['blocked_until'] ?? ''));
        if ($blockedUntil !== '') {
            $blockedTimestamp = strtotime($blockedUntil);
            if ($blockedTimestamp !== false && $blockedTimestamp > time()) {
                $pdo->commit();
                return true;
            }
        }

        $windowStarted = strtotime((string)$row['window_started_at']);
        $windowExpired = $windowStarted === false
            || $windowStarted <= (time() - ($windowHours * 3600));

        if ($windowExpired) {
            $stmt = $pdo->prepare(
                'UPDATE security_ip_guard '
                . 'SET failed_attempts = 1, window_started_at = NOW(), blocked_until = NULL '
                . 'WHERE ip_hash = ?'
            );
            $stmt->execute([$ipHash]);
            $pdo->commit();
            return false;
        }

        $failedAttempts = (int)$row['failed_attempts'] + 1;

        if ($failedAttempts >= SITE_IP_GUARD_MAX_FAILED_LOGINS) {
            $stmt = $pdo->prepare(
                "UPDATE security_ip_guard
                 SET failed_attempts = ?, blocked_until = DATE_ADD(NOW(), INTERVAL {$blockHours} HOUR)
                 WHERE ip_hash = ?"
            );
            $stmt->execute([$failedAttempts, $ipHash]);
            $pdo->commit();
            return true;
        }

        $stmt = $pdo->prepare(
            'UPDATE security_ip_guard SET failed_attempts = ?, blocked_until = NULL WHERE ip_hash = ?'
        );
        $stmt->execute([$failedAttempts, $ipHash]);
        $pdo->commit();

        return false;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function site_ip_guard_reset_after_success(PDO $pdo): void
{
    site_ip_guard_ensure_storage($pdo);

    $stmt = $pdo->prepare('DELETE FROM security_ip_guard WHERE ip_hash = ?');
    $stmt->execute([site_ip_guard_hash()]);
}

function site_ip_guard_deny_request(): never
{
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>404 Not Found</title></head><body>'
        . '<h1>Not Found</h1><p>The requested URL was not found on this server.</p>'
        . '</body></html>';
    exit;
}

function site_ip_guard_enforce(PDO $pdo): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (site_ip_guard_blocked($pdo)) {
        site_ip_guard_deny_request();
    }
}
