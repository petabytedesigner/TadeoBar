<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const ADMIN_SESSION_TIMEOUT_SECONDS = 3600;
const LOGIN_MAX_FAILED_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 10;
const LOGIN_ATTEMPT_RETENTION_DAYS = 30;
const LOGIN_DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!empty($_SESSION['admin_id'])) {
        $lastActivity = (int)($_SESSION['last_activity'] ?? 0);

        if ($lastActivity > 0 && (time() - $lastActivity) > ADMIN_SESSION_TIMEOUT_SECONDS) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'] ?? '',
                    (bool)$params['secure'],
                    (bool)$params['httponly']
                );
            }

            session_destroy();
            return;
        }

        $_SESSION['last_activity'] = time();
    }
}

function admin_current(): ?array
{
    admin_session_start();

    if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_username'])) {
        return null;
    }

    return [
        'id' => (int)$_SESSION['admin_id'],
        'username' => (string)$_SESSION['admin_username'],
    ];
}

function require_admin(): array
{
    $admin = admin_current();

    if ($admin === null) {
        redirect('/tadeo-admin/login.php');
    }

    return $admin;
}

function login_admin(array $admin): void
{
    admin_session_start();
    session_regenerate_id(true);

    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = (string)$admin['username'];
    $_SESSION['last_activity'] = time();
}

function logout_admin(): void
{
    admin_session_start();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
}

function client_ip_hash(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = 'unavailable';
    }

    return hash('sha256', 'tadeo-admin-login|' . $ip);
}

function login_attempt_username(string $username): string
{
    return mb_substr(trim($username), 0, 120, 'UTF-8');
}

function login_dummy_password_check(string $password): void
{
    password_verify($password, LOGIN_DUMMY_PASSWORD_HASH);
}

function find_admin_by_username(string $username): ?array
{
    $stmt = db()->prepare("
        SELECT id, username, password_hash, is_active
        FROM admins
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);

    $admin = $stmt->fetch();

    return $admin ?: null;
}

function is_login_blocked(string $username): bool
{
    $minutes = LOGIN_LOCK_MINUTES;

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM login_attempts
        WHERE username = ?
          AND ip_hash = ?
          AND success = 0
          AND attempted_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
    ");
    $stmt->execute([login_attempt_username($username), client_ip_hash()]);

    return (int)$stmt->fetchColumn() >= LOGIN_MAX_FAILED_ATTEMPTS;
}

function cleanup_old_login_attempts(PDO $pdo): void
{
    $days = LOGIN_ATTEMPT_RETENTION_DAYS;

    try {
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    } catch (Throwable $e) {
        // Retention cleanup is maintenance only and must never break authentication.
    }
}

function record_login_attempt(string $username, bool $success): void
{
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (username, ip_hash, success)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([
        login_attempt_username($username),
        client_ip_hash(),
        $success ? 1 : 0,
    ]);

    cleanup_old_login_attempts($pdo);

    if ($success) {
        site_ip_guard_reset_after_success($pdo);
        return;
    }

    if (site_ip_guard_register_failed_login($pdo)) {
        site_ip_guard_deny_request();
    }
}
