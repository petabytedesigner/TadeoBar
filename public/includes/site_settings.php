<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function site_setting_get(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function site_bar_name(): string
{
    return site_setting_get('bar_name', 'Tadeo Bar');
}
