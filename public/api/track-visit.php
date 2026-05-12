<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $cookieName = 'tadeo_visitor_id';
    $rawVisitorId = (string)($_COOKIE[$cookieName] ?? '');

    if (!preg_match('/^[a-f0-9]{32}$/', $rawVisitorId)) {
        $rawVisitorId = bin2hex(random_bytes(16));

        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie($cookieName, $rawVisitorId, [
            'expires' => time() + (86400 * 730),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $visitorHash = hash('sha256', 'tadeo-visitor|' . $rawVisitorId);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Tirane')))->format('Y-m-d');

    $stmt = db()->prepare("\n        INSERT IGNORE INTO visits (visitor_id, visit_date)\n        VALUES (?, ?)\n    ");
    $stmt->execute([$visitorHash, $today]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
