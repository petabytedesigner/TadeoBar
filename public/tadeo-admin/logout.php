<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

logout_admin();

redirect('/tadeo-admin/login.php');
