<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/site_settings.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

$barName = site_bar_name();

$error = '';
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$resetSuccess = (string)($_GET['reset'] ?? '') === 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } elseif ($username === '') {
        $error = 'Vendos username.';
    } elseif (is_login_blocked($username)) {
        login_dummy_password_check($password);
        record_login_attempt($username, false);
        $error = 'Username ose password i gabuar.';
    } else {
        $admin = find_admin_by_username($username);
        $passwordHash = $admin !== null
            ? (string)$admin['password_hash']
            : LOGIN_DUMMY_PASSWORD_HASH;
        $passwordValid = password_verify($password, $passwordHash);

        if (
            $admin !== null
            && (int)$admin['is_active'] === 1
            && $passwordValid
        ) {
            record_login_attempt($username, true);
            login_admin($admin);
            redirect('/tadeo-admin/dashboard.php');
        }

        record_login_attempt($username, false);
        $error = 'Username ose password i gabuar.';
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Hyrje Admin | <?= e($barName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
</head>
<body class="admin-login-page">
    <main class="login-card">
        <h1><?= e($barName) ?></h1>
        <p>Hyrje në panelin e administrimit</p>

        <?php if ($resetSuccess): ?>
            <div class="msg">Password-i u ndryshua me sukses. Mund të hysh me password-in e ri.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>

            <label>Username</label>
            <input name="username" value="<?= e($username) ?>" autocomplete="username" required>

            <label>Password</label>
            <input name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Hyr</button>
        </form>

        <a class="btn btn-secondary" href="/tadeo-admin/forgot-password.php">Harrove password-in?</a>
    </main>
</body>
</html>
