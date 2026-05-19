<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/site_settings.php';

$code = (int)($_GET['code'] ?? 500);

$messages = [
    400 => ['Kërkesë e pavlefshme', 'Kërkesa nuk mund të përpunohej.'],
    401 => ['Nuk je i autorizuar', 'Duhet të identifikohesh për të vazhduar.'],
    403 => ['Akses i ndaluar', 'Nuk ke leje për të hapur këtë faqe.'],
    404 => ['Faqja nuk u gjet', 'Faqja që kërkove nuk ekziston ose është zhvendosur.'],
    405 => ['Metodë e palejuar', 'Kjo metodë kërkese nuk lejohet.'],
    408 => ['Kërkesa skadoi', 'Serveri priti shumë gjatë për kërkesën.'],
    410 => ['Nuk ekziston më', 'Kjo faqe nuk është më e disponueshme.'],
    413 => ['Kërkesa është shumë e madhe', 'File-i ose kërkesa është më e madhe se limiti i lejuar.'],
    414 => ['URL shumë e gjatë', 'Adresa e kërkuar është shumë e gjatë.'],
    415 => ['Format i pambështetur', 'Formati i file-it nuk mbështetet.'],
    429 => ['Shumë kërkesa', 'Ka shumë kërkesa në një kohë të shkurtër. Provo përsëri më vonë.'],
    500 => ['Gabim serveri', 'Ndodhi një gabim i brendshëm.'],
    501 => ['Nuk mbështetet', 'Serveri nuk e mbështet këtë kërkesë.'],
    502 => ['Gateway i pavlefshëm', 'Serveri mori përgjigje të pavlefshme.'],
    503 => ['Shërbimi përkohësisht i padisponueshëm', 'Faqja është përkohësisht jashtë shërbimit.'],
    504 => ['Gateway timeout', 'Serveri priti shumë gjatë për përgjigje.'],
];

[$title, $description] = $messages[$code] ?? $messages[500];
$barName = site_bar_name();

http_response_code($code);
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title><?= e($code) ?> | <?= e($barName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <style>
        :root {
            color-scheme: dark;
            --bg: #070707;
            --panel: #121212;
            --gold: #f3c96d;
            --text: #f7f2e7;
            --muted: #b9b0a1;
            --border: rgba(243, 201, 109, .24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at 20% 0%, rgba(243, 201, 109, .12), transparent 34%),
                var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: min(560px, 100%);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 28px;
            background: linear-gradient(145deg, rgba(255,255,255,.055), rgba(255,255,255,.018));
            box-shadow: 0 24px 80px rgba(0,0,0,.38);
        }

        .brand {
            margin: 0 0 20px;
            color: var(--gold);
            font-family: Georgia, serif;
            font-size: clamp(34px, 8vw, 54px);
            line-height: 1;
        }

        .code {
            display: inline-flex;
            margin-bottom: 18px;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--gold);
            font-weight: 900;
            background: rgba(243, 201, 109, .08);
        }

        h1 {
            margin: 0 0 10px;
            font-size: clamp(28px, 7vw, 42px);
        }

        p {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 17px;
            line-height: 1.5;
        }

        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 16px;
            color: #111;
            background: linear-gradient(135deg, #ffdc82, #e6ad36);
            text-decoration: none;
            font-weight: 900;
        }
    </style>
</head>
<body>
    <main>
        <div class="brand"><?= e($barName) ?></div>
        <div class="code"><?= e($code) ?></div>
        <h1><?= e($title) ?></h1>
        <p><?= e($description) ?></p>
        <a href="/">Kthehu te menuja</a>
    </main>
</body>
</html>
