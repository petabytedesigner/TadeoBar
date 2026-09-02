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
$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$resetSuccess = (string)($_GET['reset'] ?? '') === 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } elseif ($identifier === '') {
        $error = 'Vendos username-in ose email-in.';
    } else {
        $admin = find_admin_by_login_identifier($identifier);
        $passwordHash = $admin !== null
            ? (string)$admin['password_hash']
            : LOGIN_DUMMY_PASSWORD_HASH;
        $passwordValid = password_verify($password, $passwordHash);
        $credentialsValid = $admin !== null
            && (int)$admin['is_active'] === 1
            && $passwordValid;

        if (is_login_blocked($identifier, $admin)) {
            if (!$credentialsValid) {
                record_login_attempt($identifier, false, $admin);
            }

            $error = 'Username-i, email-i ose password-i nuk është i saktë.';
        } elseif ($credentialsValid) {
            record_login_attempt($identifier, true, $admin);
            login_admin($admin);
            redirect('/tadeo-admin/dashboard.php');
        } else {
            record_login_attempt($identifier, false, $admin);
            $error = 'Username-i, email-i ose password-i nuk është i saktë.';
        }
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

            <label>Username ose email</label>
            <input name="identifier" value="<?= e($identifier) ?>" autocomplete="username" maxlength="190" required>

            <label>Password</label>
            <input name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Hyr</button>
        </form>

        <a class="btn btn-secondary" href="/tadeo-admin/forgot-password.php">Harrove password-in?</a>
    </main>
</body>
</html>
