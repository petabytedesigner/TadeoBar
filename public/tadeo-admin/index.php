<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (admin_current() !== null) {
    redirect('/tadeo-admin/dashboard.php');
}

redirect('/tadeo-admin/login.php');
