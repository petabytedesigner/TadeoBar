<?php
declare(strict_types=1);

/**
 * Bar Tadeo private configuration template.
 *
 * Copy this file to config.local.php and replace every placeholder.
 * NEVER commit config.local.php or real secrets to GitHub.
 */

// Database
const DB_HOST = 'YOUR_DB_HOST';
const DB_PORT = 3306;
const DB_NAME = 'YOUR_DB_NAME';
const DB_USER = 'YOUR_DB_USER';
const DB_PASS = 'YOUR_DB_PASSWORD';

// Gmail SMTP for password recovery
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USERNAME = 'YOUR_GMAIL_ADDRESS';
const SMTP_PASSWORD = 'YOUR_16_CHARACTER_APP_PASSWORD';
const SMTP_FROM_EMAIL = SMTP_USERNAME;
const SMTP_FROM_NAME = 'Bar Tadeo';

// Protected recovery destination.
// Keep the real address only in config.local.php.
const PROTECTED_RECOVERY_EMAIL = 'YOUR_PROTECTED_RECOVERY_EMAIL';

// Store only a password_hash() result here, never the plaintext protection code.
// Generate locally with:
// php -r "echo password_hash('YOUR_PRIVATE_CODE', PASSWORD_DEFAULT), PHP_EOL;"
const PROTECTED_RECOVERY_CODE_HASH = 'YOUR_PASSWORD_HASH';
