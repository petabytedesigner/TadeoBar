<?php
declare(strict_types=1);

const SITE_IP_GUARD_MAX_FAILED_LOGINS = 15;
const SITE_IP_GUARD_FAILURE_WINDOW_HOURS = 24;
const SITE_IP_GUARD_BLOCK_HOURS = 24;

function site_ip_guard_hash(): ?string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    return hash('sha256', 'tadeo-security-ip|' . $ip);
}

function site_ip_guard_missing_table(Throwable $e): bool
{
    return $e instanceof PDOException && (string)$e->getCode() === '42S02';
}

function site_ip_guard_create_storage(PDO $pdo): void
{
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
}

function site_ip_guard_blocked(PDO $pdo): bool
{
    $ipHash = site_ip_guard_hash();
    if ($ipHash === null) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT blocked_until, TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS seconds_remaining '
            . 'FROM security_ip_guard WHERE ip_hash = ? LIMIT 1'
        );
        $stmt->execute([$ipHash]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        if (!site_ip_guard_missing_table($e)) {
            throw $e;
        }

        site_ip_guard_create_storage($pdo);
        return false;
    }

    if ($row === false || $row['blocked_until'] === null) {
        return false;
    }

    if ((int)$row['seconds_remaining'] > 0) {
        return true;
    }

    $stmt = $pdo->prepare('DELETE FROM security_ip_guard WHERE ip_hash = ?');
    $stmt->execute([$ipHash]);

    return false;
}

function site_ip_guard_register_failed_login(PDO $pdo, bool $retryAfterCreate = true): bool
{
    $ipHash = site_ip_guard_hash();
    if ($ipHash === null) {
        return false;
    }

    $windowHours = SITE_IP_GUARD_FAILURE_WINDOW_HOURS;
    $blockHours = SITE_IP_GUARD_BLOCK_HOURS;
    $windowSeconds = $windowHours * 3600;

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT failed_attempts, blocked_until, '
            . 'TIMESTAMPDIFF(SECOND, window_started_at, NOW()) AS window_age_seconds, '
            . 'TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS block_seconds_remaining '
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

        if ($row['blocked_until'] !== null && (int)$row['block_seconds_remaining'] > 0) {
            $pdo->commit();
            return true;
        }

        $windowExpired = (int)$row['window_age_seconds'] >= $windowSeconds;
        if ($row['blocked_until'] !== null || $windowExpired) {
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
            'UPDATE security_ip_guard SET failed_attempts = ? WHERE ip_hash = ?'
        );
        $stmt->execute([$failedAttempts, $ipHash]);
        $pdo->commit();

        return false;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($retryAfterCreate && site_ip_guard_missing_table($e)) {
            site_ip_guard_create_storage($pdo);
            return site_ip_guard_register_failed_login($pdo, false);
        }

        throw $e;
    }
}

function site_ip_guard_reset_after_success(PDO $pdo): void
{
    $ipHash = site_ip_guard_hash();
    if ($ipHash === null) {
        return;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM security_ip_guard WHERE ip_hash = ?');
        $stmt->execute([$ipHash]);
    } catch (Throwable $e) {
        if (!site_ip_guard_missing_table($e)) {
            throw $e;
        }

        site_ip_guard_create_storage($pdo);
    }
}

function site_ip_guard_deny_request(): void
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
