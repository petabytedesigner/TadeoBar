<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function csrf_token(): string
{
    admin_session_start();

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['_csrf_token'];
}

function csrf_verify(): bool
{
    admin_session_start();

    $posted = (string)($_POST['_csrf'] ?? '');
    $session = (string)($_SESSION['_csrf_token'] ?? '');

    return $posted !== '' && $session !== '' && hash_equals($session, $posted);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
