<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

$error = '';
$username = trim((string)($_POST['username'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } elseif ($username === '') {
        $error = 'Vendos username.';
    } elseif (is_login_blocked($username)) {
        $error = 'Ka shumë tentativa të gabuara. Provo përsëri më vonë.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $admin = find_admin_by_username($username);

        if (
            $admin !== null
            && (int)$admin['is_active'] === 1
            && password_verify($password, (string)$admin['password_hash'])
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
    <title>Hyrje Admin | Tadeo Bar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-login-page">
    <main class="login-card">
        <h1>Tadeo Bar</h1>
        <p>Hyrje në panelin e administrimit</p>

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
    </main>
</body>
</html>
