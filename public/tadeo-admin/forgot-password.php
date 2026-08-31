<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/password_recovery.php';
require_once __DIR__ . '/../includes/csrf.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

$pdo = db();
$barName = site_bar_name();
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
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260831-password-recovery-1">
</head>
<body class="admin-login-page">
    <main class="login-card recovery-card">
        <h1>Rikupero password-in</h1>
        <p>
            Do të dërgojmë të njëjtin kod verifikimi 6-shifror në email-et e rikuperimit
            të konfiguruara për panelin e <?= e($barName) ?>.
        </p>

        <div class="recovery-notice">
            Kodi është i vlefshëm për 10 minuta dhe mund të përdoret vetëm një herë.
        </div>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <button type="submit">Vazhdo dhe dërgo kodin</button>
        </form>

        <a class="btn btn-secondary recovery-secondary-action" href="/tadeo-admin/login.php">Anulo</a>
    </main>
</body>
</html>
