<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/password_recovery.php';

$admin = require_admin();
$pdo = db();

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
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admins WHERE id = ? AND is_active = 1 LIMIT 1");
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
                $errors[] = 'Llogaria e adminit nuk u gjet.';
            } elseif ($currentPassword === '' || !password_verify($currentPassword, (string)$adminRow['password_hash'])) {
                $errors[] = 'Password-i aktual nuk është i saktë.';
            } elseif (!filter_var($submittedRecoveryEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Vendos një email rikuperimi të vlefshëm.';
            } else {
                recovery_setting_save($pdo, 'recovery_email', $submittedRecoveryEmail);
                $recoveryEmail = $submittedRecoveryEmail;
                $messages[] = 'Email-i i rikuperimit u ruajt me sukses.';
            }
        }

        if ($formType === 'protected_recovery') {
            admin_session_start();
            $now = time();
            $lockedUntil = (int)($_SESSION['protected_recovery_locked_until'] ?? 0);
            $specialCode = trim((string)($_POST['protected_recovery_code'] ?? ''));

            if ($protectedEmail === '') {
                $errors[] = 'Email-i i mbrojtur nuk është konfiguruar ende në konfigurimin privat.';
            } elseif (!$protectedCodeConfigured) {
                $errors[] = 'Kodi i mbrojtjes nuk është konfiguruar ende në konfigurimin privat.';
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
                $errors[] = 'Vendos fillimisht email-in tjetër të rikuperimit para se të çaktivizosh email-in e mbrojtur.';
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
                $errors[] = 'Llogaria e adminit nuk u gjet.';
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
                    $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
                    $stmt->execute([
                        password_hash($newPassword, PASSWORD_DEFAULT),
                        (int)$admin['id'],
                    ]);

                    $messages[] = 'Password-i u ndryshua me sukses.';
                }
            } else {
                $errors[] = 'Zgjedhja nuk është e vlefshme.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Cilësimet | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260831-password-recovery-1">
    <link rel="stylesheet" href="/assets/css/password-recovery.css?v=20260831-1">
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
            <p class="admin-muted">Menaxho cilësimet kryesore të menusë dhe llogarinë e adminit.</p>

            <?php foreach ($messages as $message): ?>
                <div class="msg"><?= e($message) ?></div>
            <?php endforeach; ?>

            <?php foreach ($errors as $error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <div class="settings-stack">
                <section class="form-card">
                    <h2 class="settings-card-title">Cilësimet e menusë</h2>
                    <p class="admin-muted">Këto të dhëna do përdoren nga menuja publike.</p>

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
                    <h2 class="settings-card-title">Rikuperimi i password-it</h2>
                    <p class="admin-muted">
                        Kodi 6-shifror i Forgot Password dërgohet te email-i më poshtë dhe te email-i i mbrojtur kur ai është aktiv.
                    </p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_type" value="recovery_email">

                        <div class="form-grid">
                            <div class="full">
                                <label>Email-i i rikuperimit</label>
                                <input
                                    name="recovery_email"
                                    type="email"
                                    value="<?= e($recoveryEmail) ?>"
                                    autocomplete="email"
                                    placeholder="email@example.com"
                                    required
                                >
                                <div class="help-text">Ky email mund të ndryshohet nga paneli.</div>
                            </div>

                            <div class="full">
                                <label>Password aktual</label>
                                <input name="current_password" type="password" autocomplete="current-password" required>
                                <div class="help-text">Kërkohet për të ndryshuar email-in e rikuperimit.</div>
                            </div>
                        </div>

                        <button type="submit">Ruaj email-in e rikuperimit</button>
                    </form>

                    <div class="settings-divider"></div>

                    <div class="protected-recovery-box">
                        <strong>Email i mbrojtur i rikuperimit</strong>
                        <?php if ($protectedEmail !== ''): ?>
                            <div class="protected-recovery-address"><?= e($protectedEmail) ?></div>
                            <span class="recovery-status <?= $protectedEnabled ? 'recovery-status-active' : 'recovery-status-disabled' ?>">
                                <?= $protectedEnabled ? 'Aktiv' : 'Çaktivizuar' ?>
                            </span>
                        <?php else: ?>
                            <div class="protected-recovery-address">Nuk është konfiguruar ende.</div>
                        <?php endif; ?>

                        <div class="help-text">
                            Adresa nuk mund të editohet nga paneli. Ndryshohet vetëm nga konfigurimi privat i serverit.
                        </div>
                    </div>

                    <?php if ($protectedEmail === '' || !$protectedCodeConfigured): ?>
                        <div class="recovery-config-warning">
                            Përpara aktivizimit final duhen vendosur PROTECTED_RECOVERY_EMAIL dhe PROTECTED_RECOVERY_CODE_HASH te config.local.php.
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
                    <h2 class="settings-card-title">Llogaria e adminit</h2>
                    <p class="admin-muted">Zgjidh çfarë do të ndryshosh.</p>

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
                                    <div class="help-text">Përdor një password të fortë.</div>
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
