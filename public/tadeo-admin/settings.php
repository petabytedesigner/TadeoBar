<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/password_recovery.php';

$admin = require_admin();
$pdo = db();
ensure_admin_session_version_schema($pdo);

function setting_get(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string)$value : $default;
}

function setting_save(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

function current_admin_row(PDO $pdo, int $adminId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, session_version
         FROM admins
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

$messages = [];
$errors = [];
$selectedAccountAction = (string)($_POST['account_action'] ?? 'username');

$settingsData = [
    'bar_name' => setting_get($pdo, 'bar_name', 'Bar Tadeo'),
    'default_language' => setting_get($pdo, 'default_language', 'sq'),
    'currency' => setting_get($pdo, 'currency', 'ALL'),
    'show_prices' => setting_get($pdo, 'show_prices', '1'),
];

$recoveryEmail = recovery_user_email($pdo);
$protectedEmail = protected_recovery_email();
$protectedEnabled = protected_recovery_enabled($pdo);
$protectedCodeConfigured = protected_recovery_code_configured();
$pendingRecoveryEmail = recovery_email_change_pending();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        $formType = (string)($_POST['form_type'] ?? '');

        if ($formType === 'site_settings') {
            $settingsData = [
                'bar_name' => trim((string)($_POST['bar_name'] ?? '')),
                'default_language' => (string)($_POST['default_language'] ?? 'sq'),
                'currency' => strtoupper(trim((string)($_POST['currency'] ?? 'ALL'))),
                'show_prices' => isset($_POST['show_prices']) ? '1' : '0',
            ];

            if ($settingsData['bar_name'] === '') {
                $errors[] = 'Emri i lokalit është i detyrueshëm.';
            }

            if (!in_array($settingsData['default_language'], ['sq', 'en'], true)) {
                $errors[] = 'Gjuha fillestare nuk është e vlefshme.';
            }

            if ($settingsData['currency'] === '') {
                $errors[] = 'Monedha është e detyrueshme.';
            }

            if (!$errors) {
                foreach ($settingsData as $key => $value) {
                    setting_save($pdo, $key, $value);
                }

                $messages[] = 'Cilësimet u ruajtën me sukses.';
            }
        }

        if ($formType === 'recovery_email') {
            $submittedRecoveryEmail = strtolower(trim((string)($_POST['recovery_email'] ?? '')));
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $adminRow = current_admin_row($pdo, (int)$admin['id']);

            if ($adminRow === null) {
                $errors[] = 'Llogaria nuk u gjet.';
            } elseif ($currentPassword === '' || !password_verify($currentPassword, (string)$adminRow['password_hash'])) {
                $errors[] = 'Password-i aktual nuk është i saktë.';
            } else {
                try {
                    recovery_email_change_begin($pdo, (int)$admin['id'], $submittedRecoveryEmail);
                    $pendingRecoveryEmail = recovery_email_change_pending();
                    $messages[] = 'Kodi i verifikimit u dërgua në email-in e ri. Email-i aktual mbetet aktiv derisa verifikimi të përfundojë.';
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        if ($formType === 'recovery_email_verify') {
            $verificationCode = trim((string)($_POST['recovery_email_code'] ?? ''));

            try {
                $recoveryEmail = recovery_email_change_verify(
                    $pdo,
                    (int)$admin['id'],
                    $verificationCode
                );
                $pendingRecoveryEmail = null;
                $messages[] = 'Email-i i hyrjes dhe rikuperimit u verifikua dhe u aktivizua.';
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                $pendingRecoveryEmail = recovery_email_change_pending();
            }
        }

        if ($formType === 'recovery_email_cancel') {
            recovery_email_change_cancel();
            $pendingRecoveryEmail = null;
            $messages[] = 'Ndryshimi i email-it u anulua.';
        }

        if ($formType === 'protected_recovery') {
            admin_session_start();
            $now = time();
            $lockedUntil = (int)($_SESSION['protected_recovery_locked_until'] ?? 0);
            $specialCode = trim((string)($_POST['protected_recovery_code'] ?? ''));

            if ($protectedEmail === '') {
                $errors[] = 'Email-i i mbrojtur nuk është konfiguruar ende.';
            } elseif (!$protectedCodeConfigured) {
                $errors[] = 'Kodi i mbrojtjes nuk është konfiguruar ende.';
            } elseif ($lockedUntil > $now) {
                $errors[] = 'Ka shumë tentativa të gabuara për kodin e mbrojtjes. Provo përsëri më vonë.';
            } elseif (!verify_protected_recovery_code($specialCode)) {
                $failures = (int)($_SESSION['protected_recovery_failures'] ?? 0) + 1;
                $_SESSION['protected_recovery_failures'] = $failures;

                if ($failures >= 5) {
                    $_SESSION['protected_recovery_failures'] = 0;
                    $_SESSION['protected_recovery_locked_until'] = $now + 900;
                    $errors[] = 'Kodi i mbrojtjes është i gabuar. Veprimi u bllokua për 15 minuta.';
                } else {
                    $errors[] = 'Kodi i mbrojtjes nuk është i saktë.';
                }
            } elseif ($protectedEnabled && $recoveryEmail === '') {
                $errors[] = 'Vendos fillimisht email-in e rikuperimit para se të çaktivizosh email-in e mbrojtur.';
            } else {
                unset($_SESSION['protected_recovery_failures'], $_SESSION['protected_recovery_locked_until']);
                $protectedEnabled = !$protectedEnabled;
                recovery_setting_save(
                    $pdo,
                    'protected_recovery_enabled',
                    $protectedEnabled ? '1' : '0'
                );

                $messages[] = $protectedEnabled
                    ? 'Email-i i mbrojtur i rikuperimit u riaktivizua.'
                    : 'Email-i i mbrojtur i rikuperimit u çaktivizua.';
            }
        }

        if ($formType === 'admin_account') {
            $selectedAccountAction = (string)($_POST['account_action'] ?? 'username');
            $adminRow = current_admin_row($pdo, (int)$admin['id']);
            $currentPassword = (string)($_POST['current_password'] ?? '');

            if ($adminRow === null) {
                $errors[] = 'Llogaria nuk u gjet.';
            } elseif ($currentPassword === '' || !password_verify($currentPassword, (string)$adminRow['password_hash'])) {
                $errors[] = 'Password-i aktual nuk është i saktë.';
            } elseif ($selectedAccountAction === 'username') {
                $newUsername = trim((string)($_POST['new_username'] ?? ''));

                if ($newUsername === '') {
                    $errors[] = 'Username i ri është i detyrueshëm.';
                } elseif (strlen($newUsername) < 3) {
                    $errors[] = 'Username duhet të ketë të paktën 3 karaktere.';
                } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $newUsername)) {
                    $errors[] = 'Username lejon vetëm shkronja, numra, pikë, minus dhe underscore.';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE admins SET username = ? WHERE id = ?");
                        $stmt->execute([$newUsername, (int)$admin['id']]);

                        $_SESSION['admin_username'] = $newUsername;
                        $admin['username'] = $newUsername;
                        $messages[] = 'Username u ndryshua me sukses.';
                    } catch (Throwable $e) {
                        $errors[] = 'Ky username ekziston tashmë ose nuk mund të ruhet.';
                    }
                }
            } elseif ($selectedAccountAction === 'password') {
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');

                if (strlen($newPassword) < 10) {
                    $errors[] = 'Password-i i ri duhet të ketë të paktën 10 karaktere.';
                } elseif ($newPassword !== $confirmPassword) {
                    $errors[] = 'Konfirmimi i password-it nuk përputhet.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                    if ($newHash === false) {
                        $errors[] = 'Password-i i ri nuk u ruajt dot.';
                    } else {
                        try {
                            $pdo->beginTransaction();

                            $stmt = $pdo->prepare(
                                'UPDATE admins '
                                . 'SET password_hash = ?, session_version = session_version + 1 '
                                . 'WHERE id = ? AND is_active = 1'
                            );
                            $stmt->execute([$newHash, (int)$admin['id']]);

                            if ($stmt->rowCount() !== 1) {
                                throw new RuntimeException('Llogaria nuk u gjet ose nuk është aktive.');
                            }

                            if (password_reset_table_exists($pdo)) {
                                password_reset_ensure_schema($pdo);
                                $stmt = $pdo->prepare(
                                    'UPDATE password_reset_codes SET used_at = NOW() '
                                    . 'WHERE admin_id = ? AND used_at IS NULL'
                                );
                                $stmt->execute([(int)$admin['id']]);
                            }

                            $pdo->commit();
                            refresh_current_admin_session_version($pdo, (int)$admin['id']);

                            recovery_send_security_notice(
                                recovery_recipient_emails($pdo),
                                site_bar_name() . ' - Njoftim sigurie',
                                "Password-i i panelit të administrimit u ndryshua.\n\n"
                                . "Nëse nuk e bëre ti këtë ndryshim, kontrollo menjëherë aksesin në llogari."
                            );

                            $messages[] = 'Password-i u ndryshua me sukses. Sesionet e tjera u çaktivizuan.';
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $errors[] = $e->getMessage();
                        }
                    }
                }
            } else {
                $errors[] = 'Zgjedhja nuk është e vlefshme.';
            }
        }
    }
}

$recoveryEmail = recovery_user_email($pdo);
$pendingRecoveryEmail = recovery_email_change_pending();
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Cilësimet | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <style>
        .settings-stack {
            display: grid;
            gap: 18px;
            margin-top: 18px;
        }

        .settings-card-title {
            margin: 0 0 8px;
            color: var(--gold-light);
            font-family: Georgia, serif;
            font-size: 26px;
        }

        .settings-divider {
            height: 1px;
            margin: 18px 0;
            background: rgba(255, 255, 255, .1);
        }

        .account-choice {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .account-pane[hidden] {
            display: none !important;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'settings'); ?>

        <main>
            <h1 class="admin-title">Cilësimet</h1>
            <p class="admin-muted">Menaxho cilësimet kryesore të menusë dhe të llogarisë.</p>

            <?php foreach ($messages as $message): ?>
                <div class="msg"><?= e($message) ?></div>
            <?php endforeach; ?>

            <?php foreach ($errors as $error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <div class="settings-stack">
                <section class="form-card">
                    <h2 class="settings-card-title">Cilësimet e menusë</h2>
                    <p class="admin-muted">Këto të dhëna përdoren nga menuja publike.</p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_type" value="site_settings">

                        <div class="form-grid">
                            <div>
                                <label>Emri i lokalit</label>
                                <input name="bar_name" value="<?= e($settingsData['bar_name']) ?>" required>
                            </div>

                            <div>
                                <label>Gjuha fillestare</label>
                                <select name="default_language">
                                    <option value="sq" <?= $settingsData['default_language'] === 'sq' ? 'selected' : '' ?>>Shqip</option>
                                    <option value="en" <?= $settingsData['default_language'] === 'en' ? 'selected' : '' ?>>Anglisht</option>
                                </select>
                            </div>

                            <div>
                                <label>Monedha</label>
                                <input name="currency" value="<?= e($settingsData['currency']) ?>" required>
                            </div>
                        </div>

                        <label class="checkbox-row">
                            <input type="checkbox" name="show_prices" <?= $settingsData['show_prices'] === '1' ? 'checked' : '' ?>>
                            Shfaq çmimet në menunë publike
                        </label>

                        <button type="submit">Ruaj cilësimet</button>
                    </form>
                </section>

                <section class="form-card">
                    <h2 class="settings-card-title">Email-i i hyrjes dhe rikuperimit</h2>
                    <p class="admin-muted">
                        Ky email përdoret si alternativë ndaj username-it për hyrje dhe për marrjen e kodeve të rikuperimit.
                    </p>

                    <div class="panel">
                        <strong>Email aktiv</strong>
                        <p><?= $recoveryEmail !== '' ? e($recoveryEmail) : 'Nuk është vendosur ende.' ?></p>
                    </div>

                    <?php if ($pendingRecoveryEmail !== null): ?>
                        <div class="msg">
                            Në pritje të verifikimit: <strong><?= e($pendingRecoveryEmail['email']) ?></strong>
                        </div>

                        <form method="post" autocomplete="off">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_type" value="recovery_email_verify">

                            <label>Kodi 6-shifror</label>
                            <input
                                name="recovery_email_code"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                autocomplete="one-time-code"
                                required
                            >
                            <div class="help-text">
                                Email-i i vjetër mbetet aktiv derisa kodi i dërguar në email-in e ri të verifikohet.
                            </div>

                            <button type="submit">Verifiko dhe aktivizo email-in</button>
                        </form>

                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_type" value="recovery_email_cancel">
                            <button class="btn btn-secondary" type="submit">Anulo ndryshimin e email-it</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_type" value="recovery_email">

                            <div class="form-grid">
                                <div class="full">
                                    <label>Email i ri</label>
                                    <input
                                        name="recovery_email"
                                        type="email"
                                        value="<?= e($recoveryEmail) ?>"
                                        autocomplete="email"
                                        placeholder="email@example.com"
                                        required
                                    >
                                </div>

                                <div class="full">
                                    <label>Password aktual</label>
                                    <input name="current_password" type="password" autocomplete="current-password" required>
                                    <div class="help-text">Email-i i ri aktivizohet vetëm pasi të verifikohet me kod.</div>
                                </div>
                            </div>

                            <button type="submit">Dërgo kodin e verifikimit</button>
                        </form>
                    <?php endif; ?>

                    <div class="settings-divider"></div>

                    <div class="panel">
                        <strong>Email i mbrojtur i rikuperimit</strong>
                        <?php if ($protectedEmail !== ''): ?>
                            <p><?= e($protectedEmail) ?></p>
                            <span class="badge <?= $protectedEnabled ? 'badge-active' : 'badge-hidden' ?>">
                                <?= $protectedEnabled ? 'Aktiv' : 'Çaktivizuar' ?>
                            </span>
                        <?php else: ?>
                            <p>Nuk është konfiguruar ende.</p>
                        <?php endif; ?>

                        <div class="help-text">
                            Ky email përdoret vetëm si adresë e mbrojtur për rikuperim dhe nuk mund të përdoret për hyrje në panel.
                        </div>
                    </div>

                    <?php if ($protectedEmail === '' || !$protectedCodeConfigured): ?>
                        <div class="msg">
                            Email-i i mbrojtur ose kodi privat nuk është konfiguruar ende.
                        </div>
                    <?php else: ?>
                        <form method="post" autocomplete="off">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_type" value="protected_recovery">

                            <label>Kodi i mbrojtjes</label>
                            <input
                                name="protected_recovery_code"
                                type="password"
                                inputmode="numeric"
                                autocomplete="off"
                                required
                            >
                            <div class="help-text">
                                Kërkohet vetëm për të <?= $protectedEnabled ? 'çaktivizuar' : 'riaktivizuar' ?> email-in e mbrojtur.
                            </div>

                            <button class="<?= $protectedEnabled ? 'btn-danger' : '' ?>" type="submit">
                                <?= $protectedEnabled ? 'Çaktivizo email-in e mbrojtur' : 'Riaktivizo email-in e mbrojtur' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="form-card">
                    <h2 class="settings-card-title">Llogaria</h2>
                    <p class="admin-muted">Ndrysho username-in ose password-in e hyrjes.</p>

                    <form method="post" id="adminAccountForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_type" value="admin_account">

                        <div class="form-grid">
                            <div class="full">
                                <label>Zgjidh veprimin</label>
                                <select name="account_action" id="accountAction">
                                    <option value="username" <?= $selectedAccountAction === 'username' ? 'selected' : '' ?>>Ndrysho username</option>
                                    <option value="password" <?= $selectedAccountAction === 'password' ? 'selected' : '' ?>>Ndrysho password</option>
                                </select>
                            </div>

                            <div class="full">
                                <label>Password aktual</label>
                                <input name="current_password" type="password" autocomplete="current-password" required>
                                <div class="help-text">Kërkohet për të ruajtur ndryshimin.</div>
                            </div>
                        </div>

                        <div class="settings-divider"></div>

                        <div class="account-pane" data-account-pane="username">
                            <div class="form-grid">
                                <div>
                                    <label>Username aktual</label>
                                    <input value="<?= e($admin['username']) ?>" disabled>
                                </div>

                                <div>
                                    <label>Username i ri</label>
                                    <input name="new_username" autocomplete="username" placeholder="admin">
                                </div>
                            </div>
                        </div>

                        <div class="account-pane" data-account-pane="password" hidden>
                            <div class="form-grid">
                                <div>
                                    <label>Password i ri</label>
                                    <input name="new_password" type="password" autocomplete="new-password">
                                    <div class="help-text">Përdor një password të fortë me të paktën 10 karaktere.</div>
                                </div>

                                <div>
                                    <label>Konfirmo password-in</label>
                                    <input name="confirm_password" type="password" autocomplete="new-password">
                                </div>
                            </div>
                        </div>

                        <button type="submit">Ruaj ndryshimin</button>
                    </form>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const select = document.getElementById('accountAction');
            const panes = document.querySelectorAll('[data-account-pane]');

            function syncPanes() {
                const selected = select ? select.value : 'username';
                panes.forEach(function (pane) {
                    pane.hidden = pane.dataset.accountPane !== selected;
                });
            }

            if (select) {
                select.addEventListener('change', syncPanes);
                syncPanes();
            }
        })();
    </script>
</body>
</html>
