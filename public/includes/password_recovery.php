<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/smtp_mailer.php';

const PASSWORD_RESET_CODE_TTL_MINUTES = 10;
const PASSWORD_RESET_MAX_CODE_ATTEMPTS = 10;
const PASSWORD_RESET_MAX_REQUESTS_PER_SHORT_WINDOW = 3;
const PASSWORD_RESET_SHORT_WINDOW_MINUTES = 15;
const PASSWORD_RESET_MAX_REQUESTS_PER_LONG_WINDOW = 6;
const PASSWORD_RESET_LONG_WINDOW_HOURS = 12;
const PASSWORD_RESET_REQUEST_COOLDOWN_SECONDS = 60;

const RECOVERY_EMAIL_CODE_TTL_MINUTES = 10;
const RECOVERY_EMAIL_MAX_CODE_ATTEMPTS = 10;

function recovery_setting_get(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? trim((string)$value) : $default;
}

function recovery_setting_save(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function protected_recovery_email(): string
{
    if (!defined('PROTECTED_RECOVERY_EMAIL')) {
        return '';
    }

    $email = trim((string)constant('PROTECTED_RECOVERY_EMAIL'));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '';
}

function protected_recovery_enabled(PDO $pdo): bool
{
    return recovery_setting_get($pdo, 'protected_recovery_enabled', '1') !== '0';
}

function protected_recovery_code_configured(): bool
{
    if (!defined('PROTECTED_RECOVERY_CODE_HASH')) {
        return false;
    }

    return trim((string)constant('PROTECTED_RECOVERY_CODE_HASH')) !== '';
}

function verify_protected_recovery_code(string $code): bool
{
    if (!protected_recovery_code_configured()) {
        return false;
    }

    $hash = trim((string)constant('PROTECTED_RECOVERY_CODE_HASH'));

    return password_verify(trim($code), $hash);
}

function recovery_user_email(PDO $pdo): string
{
    $email = strtolower(recovery_setting_get($pdo, 'recovery_email', ''));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function recovery_recipient_emails(PDO $pdo): array
{
    $recipients = [];

    $protected = protected_recovery_email();
    if ($protected !== '' && protected_recovery_enabled($pdo)) {
        $recipients[$protected] = $protected;
    }

    $userEmail = recovery_user_email($pdo);
    if ($userEmail !== '') {
        $recipients[$userEmail] = $userEmail;
    }

    return array_values($recipients);
}

function recovery_send_security_notice(array $recipients, string $subject, string $body): void
{
    if (!smtp_is_configured()) {
        return;
    }

    $unique = [];
    foreach ($recipients as $recipient) {
        $recipient = strtolower(trim((string)$recipient));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $unique[$recipient] = $recipient;
        }
    }

    foreach ($unique as $recipient) {
        try {
            smtp_send_text_email($recipient, $subject, $body);
        } catch (Throwable $e) {
            // Security notices are best-effort and must not undo a completed security change.
        }
    }
}

function password_reset_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute(['password_reset_codes']);

    return (int)$stmt->fetchColumn() > 0;
}

function password_reset_ensure_schema(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    if (!password_reset_table_exists($pdo)) {
        throw new RuntimeException('Sistemi i rikuperimit nuk është aktivizuar ende në databazë.');
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['password_reset_codes', 'sent_at']);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE password_reset_codes ADD COLUMN sent_at DATETIME NULL AFTER request_ip_hash'
        );
    }

    $ready = true;
}

function password_reset_request_ip_hash(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = 'unknown';
    }

    return hash('sha256', 'bar-tadeo-password-reset|' . $ip);
}

function password_reset_primary_admin(PDO $pdo): ?array
{
    ensure_admin_session_version_schema($pdo);

    $stmt = $pdo->query(
        'SELECT id, username, session_version FROM admins WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    $row = $stmt->fetch();

    return $row ?: null;
}

function password_reset_cleanup_old_rows(PDO $pdo): void
{
    $pdo->exec(
        'DELETE FROM password_reset_codes '
        . 'WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
}

function password_reset_rate_limit_message(PDO $pdo, int $adminId): ?string
{
    $ipHash = password_reset_request_ip_hash();

    $stmt = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, sent_at, NOW()) AS age_seconds '
        . 'FROM password_reset_codes '
        . 'WHERE admin_id = ? AND sent_at IS NOT NULL '
        . 'ORDER BY sent_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $ageSeconds = $stmt->fetchColumn();

    if ($ageSeconds !== false) {
        $ageSeconds = (int)$ageSeconds;
        if ($ageSeconds >= 0 && $ageSeconds < PASSWORD_RESET_REQUEST_COOLDOWN_SECONDS) {
            return 'Prit pak para se të kërkosh një kod tjetër.';
        }
    }

    $shortMinutes = PASSWORD_RESET_SHORT_WINDOW_MINUTES;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM password_reset_codes
         WHERE admin_id = ?
           AND request_ip_hash = ?
           AND sent_at IS NOT NULL
           AND sent_at >= DATE_SUB(NOW(), INTERVAL {$shortMinutes} MINUTE)"
    );
    $stmt->execute([$adminId, $ipHash]);

    if ((int)$stmt->fetchColumn() >= PASSWORD_RESET_MAX_REQUESTS_PER_SHORT_WINDOW) {
        return 'Ka shumë kërkesa për rikuperim. Provo përsëri më vonë.';
    }

    $longHours = PASSWORD_RESET_LONG_WINDOW_HOURS;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM password_reset_codes
         WHERE admin_id = ?
           AND sent_at IS NOT NULL
           AND sent_at >= DATE_SUB(NOW(), INTERVAL {$longHours} HOUR)"
    );
    $stmt->execute([$adminId]);

    if ((int)$stmt->fetchColumn() >= PASSWORD_RESET_MAX_REQUESTS_PER_LONG_WINDOW) {
        return 'Kufiri i kodeve të rikuperimit është arritur. Provo përsëri pas 12 orësh.';
    }

    return null;
}

function password_reset_issue(PDO $pdo): int
{
    password_reset_ensure_schema($pdo);

    if (!smtp_is_configured()) {
        throw new RuntimeException('Dërgimi i email-it nuk është konfiguruar ende.');
    }

    $recipients = recovery_recipient_emails($pdo);
    if ($recipients === []) {
        throw new RuntimeException('Nuk ka email rikuperimi të konfiguruar.');
    }

    $admin = password_reset_primary_admin($pdo);
    if ($admin === null) {
        throw new RuntimeException('Nuk u gjet një llogari aktive për rikuperim.');
    }

    password_reset_cleanup_old_rows($pdo);

    $code = (string)random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    if ($codeHash === false) {
        throw new RuntimeException('Kodi i rikuperimit nuk u krijua dot.');
    }

    $barName = site_bar_name();
    $subject = $barName . ' - Kodi i rikuperimit';
    $body = "Kodi yt 6-shifror për rikuperimin e password-it të {$barName} është:\n\n"
        . $code
        . "\n\nKodi skadon pas " . PASSWORD_RESET_CODE_TTL_MINUTES . " minutash dhe mund të përdoret vetëm një herë."
        . "\nNëse nuk e kërkove këtë kod, mund ta injorosh këtë email.";

    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare(
            'SELECT id FROM admins WHERE id = ? AND is_active = 1 FOR UPDATE'
        );
        $lock->execute([(int)$admin['id']]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Llogaria nuk është më aktive.');
        }

        $rateLimitMessage = password_reset_rate_limit_message($pdo, (int)$admin['id']);
        if ($rateLimitMessage !== null) {
            throw new RuntimeException($rateLimitMessage);
        }

        $stmt = $pdo->prepare(
            'UPDATE password_reset_codes SET used_at = NOW() '
            . 'WHERE admin_id = ? AND used_at IS NULL'
        );
        $stmt->execute([(int)$admin['id']]);

        $ttlMinutes = PASSWORD_RESET_CODE_TTL_MINUTES;
        $stmt = $pdo->prepare(
            "INSERT INTO password_reset_codes
                (admin_id, code_hash, request_ip_hash, sent_at, expires_at)
             VALUES (?, ?, ?, NULL, DATE_ADD(NOW(), INTERVAL {$ttlMinutes} MINUTE))"
        );
        $stmt->execute([
            (int)$admin['id'],
            $codeHash,
            password_reset_request_ip_hash(),
        ]);
        $resetId = (int)$pdo->lastInsertId();

        $deliveredCount = 0;
        foreach ($recipients as $recipient) {
            try {
                smtp_send_text_email($recipient, $subject, $body);
                $deliveredCount++;
            } catch (Throwable $e) {
                // Continue: one verified recovery destination is enough to keep the code usable.
            }
        }

        if ($deliveredCount === 0) {
            throw new RuntimeException('Kodi nuk u dërgua dot me email. Provo përsëri më vonë.');
        }

        $stmt = $pdo->prepare(
            'UPDATE password_reset_codes SET sent_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$resetId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    admin_session_start();
    $_SESSION['password_reset_id'] = $resetId;

    return $resetId;
}

function password_reset_current_row(PDO $pdo, int $resetId, bool $forUpdate = false): ?array
{
    if ($resetId <= 0) {
        return null;
    }

    password_reset_ensure_schema($pdo);

    $sql =
        'SELECT id, admin_id, code_hash, attempts, expires_at, used_at, sent_at, '
        . 'TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining '
        . 'FROM password_reset_codes WHERE id = ? LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$resetId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function password_reset_verify_code(PDO $pdo, int $resetId, string $code): array
{
    password_reset_ensure_schema($pdo);
    $pdo->beginTransaction();

    try {
        $row = password_reset_current_row($pdo, $resetId, true);

        if ($row === null || $row['used_at'] !== null || $row['sent_at'] === null) {
            $pdo->commit();
            throw new RuntimeException('Kjo kërkesë rikuperimi nuk është më e vlefshme.');
        }

        if ((int)$row['seconds_remaining'] <= 0) {
            $stmt = $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?');
            $stmt->execute([$resetId]);
            $pdo->commit();
            throw new RuntimeException('Kodi ka skaduar. Kërko një kod të ri.');
        }

        $attempts = (int)$row['attempts'];
        if ($attempts >= PASSWORD_RESET_MAX_CODE_ATTEMPTS) {
            $stmt = $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?');
            $stmt->execute([$resetId]);
            $pdo->commit();
            throw new RuntimeException('Ky kod është bllokuar pas shumë tentativave. Kërko një kod të ri.');
        }

        $validFormat = preg_match('/^\d{6}$/', trim($code)) === 1;
        $validCode = $validFormat && password_verify(trim($code), (string)$row['code_hash']);

        if (!$validCode) {
            $newAttempts = $attempts + 1;
            $usedAtSql = $newAttempts >= PASSWORD_RESET_MAX_CODE_ATTEMPTS ? ', used_at = NOW()' : '';
            $stmt = $pdo->prepare(
                'UPDATE password_reset_codes SET attempts = ?' . $usedAtSql . ' WHERE id = ?'
            );
            $stmt->execute([$newAttempts, $resetId]);
            $pdo->commit();

            if ($newAttempts >= PASSWORD_RESET_MAX_CODE_ATTEMPTS) {
                throw new RuntimeException('Kodi është i gabuar dhe kërkesa u bllokua. Kërko një kod të ri.');
            }

            throw new RuntimeException('Kodi 6-shifror nuk është i saktë.');
        }

        $stmt = $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?');
        $stmt->execute([$resetId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    admin_session_start();
    session_regenerate_id(true);
    $_SESSION['password_reset_admin_id'] = (int)$row['admin_id'];
    $_SESSION['password_reset_authorized_until'] = time() + (PASSWORD_RESET_CODE_TTL_MINUTES * 60);
    unset($_SESSION['password_reset_id']);

    return ['admin_id' => (int)$row['admin_id']];
}

function password_reset_authorized_admin_id(): ?int
{
    admin_session_start();

    $adminId = (int)($_SESSION['password_reset_admin_id'] ?? 0);
    $authorizedUntil = (int)($_SESSION['password_reset_authorized_until'] ?? 0);

    if ($adminId <= 0 || $authorizedUntil < time()) {
        unset($_SESSION['password_reset_admin_id'], $_SESSION['password_reset_authorized_until']);
        return null;
    }

    return $adminId;
}

function password_reset_complete(PDO $pdo, int $adminId, string $newPassword): void
{
    if ($adminId <= 0) {
        throw new RuntimeException('Autorizimi për ndryshimin e password-it ka skaduar.');
    }

    if (strlen($newPassword) < 10) {
        throw new RuntimeException('Password-i i ri duhet të ketë të paktën 10 karaktere.');
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Password-i i ri nuk u ruajt dot.');
    }

    ensure_admin_session_version_schema($pdo);
    password_reset_ensure_schema($pdo);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'UPDATE admins '
            . 'SET password_hash = ?, session_version = session_version + 1 '
            . 'WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$hash, $adminId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Llogaria nuk u gjet ose nuk është aktive.');
        }

        $stmt = $pdo->prepare(
            'UPDATE password_reset_codes SET used_at = NOW() '
            . 'WHERE admin_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$adminId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    admin_session_start();
    unset(
        $_SESSION['password_reset_admin_id'],
        $_SESSION['password_reset_authorized_until'],
        $_SESSION['password_reset_id']
    );
    session_regenerate_id(true);

    $barName = site_bar_name();
    recovery_send_security_notice(
        recovery_recipient_emails($pdo),
        $barName . ' - Njoftim sigurie',
        "Password-i i panelit të administrimit u ndryshua.\n\n"
        . "Nëse nuk e bëre ti këtë ndryshim, kontrollo menjëherë aksesin në llogari."
    );
}

function recovery_email_change_pending(): ?array
{
    admin_session_start();

    $email = strtolower(trim((string)($_SESSION['pending_recovery_email'] ?? '')));
    $hash = (string)($_SESSION['pending_recovery_email_code_hash'] ?? '');
    $expires = (int)($_SESSION['pending_recovery_email_expires_at'] ?? 0);
    $attempts = (int)($_SESSION['pending_recovery_email_attempts'] ?? 0);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $hash === '' || $expires <= time()) {
        recovery_email_change_cancel();
        return null;
    }

    return [
        'email' => $email,
        'expires_at' => $expires,
        'attempts' => $attempts,
    ];
}

function recovery_email_change_begin(PDO $pdo, int $adminId, string $newEmail): void
{
    $newEmail = strtolower(trim($newEmail));

    if ($adminId <= 0 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vendos një email rikuperimi të vlefshëm.');
    }

    $protectedEmail = protected_recovery_email();
    if ($protectedEmail !== '' && strcasecmp($newEmail, $protectedEmail) === 0) {
        throw new RuntimeException('Email-i i rikuperimit duhet të jetë ndryshe nga email-i i mbrojtur.');
    }

    if (strcasecmp($newEmail, recovery_user_email($pdo)) === 0) {
        throw new RuntimeException('Ky email është tashmë aktiv.');
    }

    if (!smtp_is_configured()) {
        throw new RuntimeException('Dërgimi i email-it nuk është konfiguruar ende.');
    }

    $code = (string)random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    if ($codeHash === false) {
        throw new RuntimeException('Kodi i verifikimit nuk u krijua dot.');
    }

    $barName = site_bar_name();

    try {
        smtp_send_text_email(
            $newEmail,
            $barName . ' - Verifiko email-in',
            "Kodi 6-shifror për verifikimin e email-it të ri është:\n\n{$code}\n\n"
            . 'Kodi skadon pas ' . RECOVERY_EMAIL_CODE_TTL_MINUTES . ' minutash.'
        );
    } catch (Throwable $e) {
        throw new RuntimeException('Kodi i verifikimit nuk u dërgua dot. Kontrollo email-in dhe provo përsëri.', 0, $e);
    }

    admin_session_start();
    $_SESSION['pending_recovery_email'] = $newEmail;
    $_SESSION['pending_recovery_email_code_hash'] = $codeHash;
    $_SESSION['pending_recovery_email_expires_at'] = time() + (RECOVERY_EMAIL_CODE_TTL_MINUTES * 60);
    $_SESSION['pending_recovery_email_attempts'] = 0;
    $_SESSION['pending_recovery_email_admin_id'] = $adminId;
}

function recovery_email_change_verify(PDO $pdo, int $adminId, string $code): string
{
    admin_session_start();
    $pending = recovery_email_change_pending();

    if ($pending === null || (int)($_SESSION['pending_recovery_email_admin_id'] ?? 0) !== $adminId) {
        throw new RuntimeException('Verifikimi i email-it ka skaduar. Fillo përsëri.');
    }

    $attempts = (int)($_SESSION['pending_recovery_email_attempts'] ?? 0);
    if ($attempts >= RECOVERY_EMAIL_MAX_CODE_ATTEMPTS) {
        recovery_email_change_cancel();
        throw new RuntimeException('Kodi i verifikimit u bllokua pas shumë tentativave. Fillo përsëri.');
    }

    $hash = (string)$_SESSION['pending_recovery_email_code_hash'];
    $valid = preg_match('/^\d{6}$/', trim($code)) === 1
        && password_verify(trim($code), $hash);

    if (!$valid) {
        $attempts++;
        $_SESSION['pending_recovery_email_attempts'] = $attempts;

        if ($attempts >= RECOVERY_EMAIL_MAX_CODE_ATTEMPTS) {
            recovery_email_change_cancel();
            throw new RuntimeException('Kodi është i gabuar dhe verifikimi u anulua. Fillo përsëri.');
        }

        throw new RuntimeException('Kodi 6-shifror nuk është i saktë.');
    }

    $newEmail = (string)$pending['email'];
    $oldEmail = recovery_user_email($pdo);
    recovery_setting_save($pdo, 'recovery_email', $newEmail);
    recovery_email_change_cancel();

    $recipients = [$oldEmail, $newEmail];
    $protected = protected_recovery_email();
    if ($protected !== '' && protected_recovery_enabled($pdo)) {
        $recipients[] = $protected;
    }

    $barName = site_bar_name();
    recovery_send_security_notice(
        $recipients,
        $barName . ' - Email-i i rikuperimit u ndryshua',
        "Email-i i hyrjes dhe rikuperimit u ndryshua në {$newEmail}.\n\n"
        . "Nëse nuk e bëre ti këtë ndryshim, kontrollo menjëherë aksesin në llogari."
    );

    return $newEmail;
}

function recovery_email_change_cancel(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        admin_session_start();
    }

    unset(
        $_SESSION['pending_recovery_email'],
        $_SESSION['pending_recovery_email_code_hash'],
        $_SESSION['pending_recovery_email_expires_at'],
        $_SESSION['pending_recovery_email_attempts'],
        $_SESSION['pending_recovery_email_admin_id']
    );
}
