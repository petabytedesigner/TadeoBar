<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

function wifi_get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function wifi_save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([$key, $value]);
}

function wifi_qr_escape_value(string $value): string
{
    return strtr($value, [
        '\\' => '\\\\',
        ';' => '\\;',
        ',' => '\\,',
        ':' => '\\:',
        '"' => '\\"',
    ]);
}

function wifi_qr_payload_local(string $ssid, string $password, string $security): string
{
    $ssid = wifi_qr_escape_value($ssid);

    if ($security === 'nopass') {
        return 'WIFI:T:nopass;S:' . $ssid . ';;';
    }

    return 'WIFI:T:WPA;S:' . $ssid . ';P:' . wifi_qr_escape_value($password) . ';;';
}

$data = [
    'ssid' => wifi_get_setting($pdo, 'wifi_ssid', 'TadeoBar'),
    'password' => wifi_get_setting($pdo, 'wifi_password', ''),
    'security' => wifi_get_setting($pdo, 'wifi_security', 'WPA'),
];

if (!in_array($data['security'], ['WPA', 'nopass'], true)) {
    $data['security'] = 'WPA';
}

$error = '';
$flash = (string)($_GET['msg'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        $data = [
            'ssid' => trim((string)($_POST['ssid'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
            'security' => (string)($_POST['security'] ?? 'WPA'),
        ];

        if (!in_array($data['security'], ['WPA', 'nopass'], true)) {
            $data['security'] = 'WPA';
        }

        if ($data['ssid'] === '') {
            $error = 'Vendos emrin e WiFi.';
        } elseif ($data['security'] !== 'nopass' && strlen($data['password']) < 8) {
            $error = 'Password-i i WiFi duhet të ketë të paktën 8 karaktere.';
        } else {
            wifi_save_setting($pdo, 'wifi_ssid', $data['ssid']);
            wifi_save_setting($pdo, 'wifi_password', $data['password']);
            wifi_save_setting($pdo, 'wifi_security', $data['security']);

            redirect('/tadeo-admin/wifi.php?msg=WiFi u përditësua');
        }
    }
}

$qrPayload = wifi_qr_payload_local($data['ssid'], $data['password'], $data['security']);
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>WiFi | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-wifi-local-qr-1">
    <style>
        .wifi-panel-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 420px);
            gap: 18px;
            align-items: start;
        }

        .wifi-qr-card {
            padding: 22px;
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 22px;
            background: rgba(17, 17, 17, .92);
        }

        .wifi-qr-box {
            margin-top: 16px;
            padding: 18px;
            border-radius: 24px;
            background: #fff;
            border: 1px solid rgba(243, 201, 109, .24);
            box-shadow: 0 18px 60px rgba(0, 0, 0, .38);
        }

        .wifi-qr-svg {
            width: 100%;
            height: auto;
            display: block;
        }

        .wifi-preview-list {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .wifi-preview-item {
            padding: 14px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, .1);
            background: rgba(255, 255, 255, .045);
        }

        .wifi-preview-item small {
            display: block;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .wifi-preview-item strong {
            color: var(--gold-light);
        }

        .qr-payload {
            margin-top: 14px;
            padding: 12px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .045);
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
            word-break: break-all;
        }

        @media (max-width: 900px) {
            .wifi-panel-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'wifi'); ?>

        <main>
            <h1 class="admin-title">WiFi</h1>
            <p class="admin-muted">
                Menaxho emrin dhe password-in e WiFi. QR krijohet automatikisht dhe ndryshon sa herë ndryshon WiFi.
            </p>

            <?php if ($flash !== ''): ?>
                <div class="msg"><?= e($flash) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="wifi-panel-grid">
                <form class="form-card" method="post">
                    <?= csrf_field() ?>

                    <div class="form-grid">
                        <div class="full">
                            <label>Emri i WiFi / SSID</label>
                            <input name="ssid" value="<?= e($data['ssid']) ?>" required>
                        </div>

                        <div>
                            <label>Siguria</label>
                            <select name="security" required>
                                <option value="WPA" <?= $data['security'] === 'WPA' ? 'selected' : '' ?>>WPA / WPA2</option>
                                <option value="nopass" <?= $data['security'] === 'nopass' ? 'selected' : '' ?>>Pa password</option>
                            </select>
                        </div>

                        <div>
                            <label>Password</label>
                            <input name="password" value="<?= e($data['password']) ?>" autocomplete="off">
                            <div class="help-text">Nëse zgjedh WPA/WPA2, password-i duhet të ketë të paktën 8 karaktere.</div>
                        </div>
                    </div>

                    <button type="submit">Ruaj WiFi dhe rifresko QR</button>
                </form>

                <aside class="wifi-qr-card">
                    <h2>QR Code</h2>
                    <p class="admin-muted">Ky QR gjenerohet lokalisht në browser me algoritëm standard QR. Nuk përdor shërbim të jashtëm.</p>

                    <div class="wifi-qr-box" id="wifiQrBox" data-qr-payload="<?= e($qrPayload) ?>">
                        QR po krijohet...
                    </div>

                    <div class="wifi-preview-list">
                        <div class="wifi-preview-item">
                            <small>SSID</small>
                            <strong><?= e($data['ssid']) ?></strong>
                        </div>

                        <div class="wifi-preview-item">
                            <small>Siguria</small>
                            <strong><?= e($data['security'] === 'nopass' ? 'Pa password' : 'WPA / WPA2') ?></strong>
                        </div>

                        <div class="wifi-preview-item">
                            <small>Statusi</small>
                            <strong>QR automatik</strong>
                        </div>
                    </div>

                    <div class="qr-payload"><?= e($qrPayload) ?></div>
                </aside>
            </section>
        </main>
    </div>

    <script src="/assets/js/wifi-qr.js?v=20260512-wifi-local-qr-1"></script>
</body>
</html>
