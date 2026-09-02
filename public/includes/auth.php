<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const ADMIN_SESSION_TIMEOUT_SECONDS = 3600;
const LOGIN_MAX_FAILED_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 10;
const LOGIN_ATTEMPT_RETENTION_DAYS = 30;
const LOGIN_DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

function ensure_admin_session_version_schema(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['admins', 'session_version']);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE admins ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash'
        );
    }

    $ready = true;
}

function admin_clear_session(): void
{
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

    if (empty($_SESSION['admin_id'])) {
        return;
    }

    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > ADMIN_SESSION_TIMEOUT_SECONDS) {
        admin_clear_session();
        return;
    }

    $pdo = db();
    ensure_admin_session_version_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT username, is_active, session_version FROM admins WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $row = $stmt->fetch();

    $sessionVersion = (int)($_SESSION['admin_session_version'] ?? 0);
    if (
        $row === false
        || (int)$row['is_active'] !== 1
        || $sessionVersion <= 0
        || $sessionVersion !== (int)$row['session_version']
    ) {
        admin_clear_session();
        return;
    }

    $_SESSION['admin_username'] = (string)$row['username'];
    $_SESSION['last_activity'] = time();
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
    $pdo = db();
    ensure_admin_session_version_schema($pdo);

    if (!isset($admin['session_version'])) {
        $stmt = $pdo->prepare(
            'SELECT session_version FROM admins WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([(int)$admin['id']]);
        $sessionVersion = $stmt->fetchColumn();

        if ($sessionVersion === false) {
            throw new RuntimeException('Llogaria nuk është më aktive.');
        }

        $admin['session_version'] = (int)$sessionVersion;
    }

    admin_session_start();
    session_regenerate_id(true);

    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = (string)$admin['username'];
    $_SESSION['admin_session_version'] = (int)$admin['session_version'];
    $_SESSION['last_activity'] = time();
}

function refresh_current_admin_session_version(PDO $pdo, int $adminId): void
{
    ensure_admin_session_version_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT username, session_version FROM admins WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return;
    }

    admin_session_start();
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_username'] = (string)$row['username'];
    $_SESSION['admin_session_version'] = (int)$row['session_version'];
    $_SESSION['last_activity'] = time();
    session_regenerate_id(true);
}

function logout_admin(): void
{
    admin_session_start();
    admin_clear_session();
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

function auth_recovery_email(PDO $pdo): string
{
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
    );
    $stmt->execute(['recovery_email']);
    $email = strtolower(trim((string)($stmt->fetchColumn() ?: '')));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function find_admin_by_login_identifier(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $pdo = db();
    ensure_admin_session_version_schema($pdo);

    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $recoveryEmail = auth_recovery_email($pdo);

        if ($recoveryEmail === '' || strcasecmp($identifier, $recoveryEmail) !== 0) {
            return null;
        }

        $stmt = $pdo->query(
            'SELECT id, username, password_hash, session_version, is_active '
            . 'FROM admins WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
        );
        $admin = $stmt->fetch();

        return $admin ?: null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, session_version, is_active '
        . 'FROM admins WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$identifier]);
    $admin = $stmt->fetch();

    return $admin ?: null;
}

function find_admin_by_username(string $username): ?array
{
    return find_admin_by_login_identifier($username);
}

function login_attempt_key(string $identifier, ?array $admin = null): string
{
    if ($admin !== null && isset($admin['id'])) {
        return 'admin:' . (int)$admin['id'];
    }

    $identifier = trim($identifier);
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $identifier = strtolower($identifier);
    }

    return login_attempt_username($identifier);
}

function is_login_blocked(string $identifier, ?array $admin = null): bool
{
    $minutes = LOGIN_LOCK_MINUTES;
    $attemptKey = login_attempt_key($identifier, $admin);

    $stmt = db()->prepare(
        "SELECT COUNT(*)
        FROM login_attempts
        WHERE username = ?
          AND ip_hash = ?
          AND success = 0
          AND attempted_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
    );
    $stmt->execute([$attemptKey, client_ip_hash()]);

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

function record_login_attempt(string $identifier, bool $success, ?array $admin = null): void
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_hash, success) VALUES (?, ?, ?)'
    );
    $stmt->execute([
        login_attempt_key($identifier, $admin),
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
