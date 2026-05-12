<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();
$tz = new DateTimeZone('Europe/Tirane');
$todayDate = new DateTimeImmutable('today', $tz);

function date_ymd(DateTimeImmutable $date): string
{
    return $date->format('Y-m-d');
}

function count_unique_visitors(PDO $pdo, string $from, string $to): int
{
    $stmt = $pdo->prepare("\n        SELECT COUNT(DISTINCT visitor_id)\n        FROM visits\n        WHERE visit_date BETWEEN ? AND ?\n    ");
    $stmt->execute([$from, $to]);
    return (int)$stmt->fetchColumn();
}

function count_all_time(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(DISTINCT visitor_id) FROM visits")->fetchColumn();
}

$today = date_ymd($todayDate);
$yesterday = date_ymd($todayDate->modify('-1 day'));

$metrics = [
    ['Sot', count_unique_visitors($pdo, $today, $today)],
    ['Dje', count_unique_visitors($pdo, $yesterday, $yesterday)],
    ['7 ditët e fundit', count_unique_visitors($pdo, date_ymd($todayDate->modify('-6 days')), $today)],
    ['30 ditët e fundit', count_unique_visitors($pdo, date_ymd($todayDate->modify('-29 days')), $today)],
    ['90 ditët e fundit', count_unique_visitors($pdo, date_ymd($todayDate->modify('-89 days')), $today)],
    ['12 muajt e fundit', count_unique_visitors($pdo, date_ymd($todayDate->modify('-12 months')), $today)],
    ['36 muajt e fundit', count_unique_visitors($pdo, date_ymd($todayDate->modify('-36 months')), $today)],
    ['Gjithsej', count_all_time($pdo)],
];

$chartFrom = date_ymd($todayDate->modify('-29 days'));
$stmt = $pdo->prepare("\n    SELECT visit_date, COUNT(*) AS total\n    FROM visits\n    WHERE visit_date BETWEEN ? AND ?\n    GROUP BY visit_date\n    ORDER BY visit_date\n");
$stmt->execute([$chartFrom, $today]);
$rows = $stmt->fetchAll();

$countsByDate = [];
foreach ($rows as $row) {
    $countsByDate[(string)$row['visit_date']] = (int)$row['total'];
}

$chartDays = [];
$maxValue = 1;
for ($i = 29; $i >= 0; $i--) {
    $date = $todayDate->modify('-' . $i . ' days');
    $key = date_ymd($date);
    $value = $countsByDate[$key] ?? 0;
    $maxValue = max($maxValue, $value);
    $chartDays[] = [
        'date' => $key,
        'label' => $date->format('d/m'),
        'value' => $value,
    ];
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Analitika | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-analytics-1">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'analytics'); ?>

        <main>
            <h1 class="admin-title">Analitika</h1>
            <p class="admin-muted">
                Shfaq vizitorët unikë që kanë hapur faqen publike të menusë.
            </p>

            <section class="grid">
                <?php foreach ($metrics as [$label, $value]): ?>
                    <article class="stat-card">
                        <small><?= e($label) ?></small>
                        <strong><?= e(number_format((int)$value)) ?></strong>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel analytics-panel">
                <h2>Vizitorë gjatë 30 ditëve të fundit</h2>
                <p class="admin-muted">
                    Çdo kolonë tregon vizitorët unikë për atë ditë.
                </p>

                <div class="analytics-chart" aria-label="Grafiku i vizitorëve për 30 ditët e fundit">
                    <?php foreach ($chartDays as $day): ?>
                        <?php $height = (int)round(((int)$day['value'] / $maxValue) * 100); ?>
                        <div class="analytics-bar-wrap" title="<?= e($day['date']) ?>: <?= e($day['value']) ?>">
                            <div class="analytics-bar-value"><?= e($day['value']) ?></div>
                            <div class="analytics-bar" style="height: <?= e(max(4, $height)) ?>%;"></div>
                            <div class="analytics-bar-label"><?= e($day['label']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
