<?php
declare(strict_types=1);

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function versioned_public_file_url(string $relativePath): string
{
    $relativePath = ltrim(trim($relativePath), '/');

    if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
        return '/';
    }

    $absolutePath = dirname(__DIR__) . '/' . $relativePath;
    $version = is_file($absolutePath) ? (string)(@filemtime($absolutePath) ?: 0) : '0';

    return '/' . $relativePath . '?v=' . rawurlencode($version);
}
