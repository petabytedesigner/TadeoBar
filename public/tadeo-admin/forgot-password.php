<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/password_recovery.php';
require_once __DIR__ . '/../includes/csrf.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

function mask_recovery_email(string $email): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $length = strlen($local);

    if ($length <= 1) {
        $maskedLocal = substr($local, 0, 1) . '***';
    } elseif ($length <= 4) {
        $maskedLocal = substr($local, 0, 1) . '***' . substr($local, -1);
    } else {
        $maskedLocal = substr($local, 0, 2) . '******' . substr($local, -2);
    }

    return $maskedLocal . '@' . $domain;
}

$pdo = db();
$barName = site_bar_name();
$recoveryEmail = recovery_user_email($pdo);
$maskedRecoveryEmail = mask_recovery_email($recoveryEmail);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        try {
            password_reset_issue($pdo);
            redirect('/tadeo-admin/verify-reset-code.php');
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
    <title>Rikupero password-in | <?= e($barName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
</head>
<body class="admin-login-page">
    <main class="login-card">
        <h1>Rikupero password-in</h1>
        <p>Do të dërgojmë një kod verifikimi 6-shifror në email-in e rikuperimit:</p>

        <?php if ($maskedRecoveryEmail !== ''): ?>
            <div class="msg"><strong><?= e($maskedRecoveryEmail) ?></strong></div>
        <?php endif; ?>

        <p class="admin-muted">
            Kodi është i vlefshëm për 10 minuta dhe mund të përdoret vetëm një herë.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <button type="submit">Dërgo kodin</button>
        </form>

        <a class="btn btn-secondary" href="/tadeo-admin/login.php">Anulo</a>
    </main>
</body>
</html>
