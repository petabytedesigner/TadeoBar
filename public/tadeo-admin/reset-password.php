<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/password_recovery.php';
require_once __DIR__ . '/../includes/csrf.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

$adminId = password_reset_authorized_admin_id();
if ($adminId === null) {
    redirect('/tadeo-admin/forgot-password.php');
}

$pdo = db();
$barName = site_bar_name();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (strlen($newPassword) < 10) {
            $error = 'Password-i i ri duhet të ketë të paktën 10 karaktere.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Konfirmimi i password-it nuk përputhet.';
        } else {
            try {
                password_reset_complete($pdo, $adminId, $newPassword);
                redirect('/tadeo-admin/login.php?reset=success');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Password i ri | <?= e($barName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260831-password-recovery-1">
</head>
<body class="admin-login-page">
    <main class="login-card recovery-card">
        <h1>Vendos password të ri</h1>
        <p>Kodi u verifikua. Tani cakto password-in e ri për panelin e administrimit.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>

            <label for="newPassword">Password i ri</label>
            <input id="newPassword" name="new_password" type="password" autocomplete="new-password" minlength="10" required autofocus>
            <div class="help-text">Minimumi 10 karaktere.</div>

            <label for="confirmPassword">Konfirmo password-in</label>
            <input id="confirmPassword" name="confirm_password" type="password" autocomplete="new-password" minlength="10" required>

            <button type="submit">Ruaj password-in e ri</button>
        </form>
    </main>
</body>
</html>
