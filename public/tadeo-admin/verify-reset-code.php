<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/password_recovery.php';
require_once __DIR__ . '/../includes/csrf.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

admin_session_start();
$resetId = (int)($_SESSION['password_reset_id'] ?? 0);

if ($resetId <= 0) {
    redirect('/tadeo-admin/forgot-password.php');
}

$pdo = db();
$barName = site_bar_name();
$error = '';
$code = trim((string)($_POST['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Vendos kodin e plotë 6-shifror.';
    } else {
        try {
            password_reset_verify_code($pdo, $resetId, $code);
            redirect('/tadeo-admin/reset-password.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Verifiko kodin | <?= e($barName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260831-password-recovery-1">
    <link rel="stylesheet" href="/assets/css/password-recovery.css?v=20260831-1">
</head>
<body class="admin-login-page">
    <main class="login-card recovery-card">
        <h1>Vendos kodin</h1>
        <p>
            Kontrollo email-et e rikuperimit dhe vendos kodin 6-shifror që sapo u dërgua.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>

            <label for="resetCode">Kodi 6-shifror</label>
            <input
                id="resetCode"
                class="recovery-code-input"
                name="code"
                value="<?= e($code) ?>"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                autocomplete="one-time-code"
                required
                autofocus
            >

            <button type="submit">Verifiko kodin</button>
        </form>

        <a class="btn btn-secondary recovery-secondary-action" href="/tadeo-admin/forgot-password.php">Kërko kod të ri</a>
        <a class="recovery-text-link" href="/tadeo-admin/login.php">Kthehu te hyrja</a>
    </main>
</body>
</html>
