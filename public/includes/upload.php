<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const PRODUCT_UPLOAD_MAX_BYTES = 2097152; // 2 MB
const CATEGORY_UPLOAD_MAX_BYTES = 2097152; // 2 MB

function slugify_filename(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));

    $map = [
        'ë' => 'e',
        'ç' => 'c',
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'é' => 'e',
        'è' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ö' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        '’' => '',
        "'" => '',
        '&' => 'and',
    ];

    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'image';
}

function product_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/products';
}

function category_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/categories';
}

function public_product_upload_path(string $filename): string
{
    return 'uploads/products/' . $filename;
}

function public_category_upload_path(string $filename): string
{
    return 'uploads/categories/' . $filename;
}

function ensure_safe_upload_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Nuk u krijua dot folderi i imazheve.');
    }

    $htaccess = $dir . '/.htaccess';

    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n");
        chmod($htaccess, 0644);
    }
}

function ensure_product_upload_dir(): void
{
    ensure_safe_upload_dir(product_upload_dir());
}

function ensure_category_upload_dir(): void
{
    ensure_safe_upload_dir(category_upload_dir());
}

function handle_image_upload(
    string $fieldName,
    string $baseName,
    string $targetDir,
    callable $publicPathResolver,
    int $maxBytes,
    ?string $currentPath = null
): ?string {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $currentPath;
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ngarkimi i imazhit dështoi.');
    }

    if (($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
        throw new RuntimeException('Imazhi duhet të jetë maksimumi 2 MB.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('File-i i ngarkuar nuk është i vlefshëm.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Lejohen vetëm imazhe JPG, PNG ose WEBP.');
    }

    ensure_safe_upload_dir($targetDir);

    $extension = $allowed[$mime];
    $slug = slugify_filename($baseName);
    $filename = $slug . '.' . $extension;
    $target = $targetDir . '/' . $filename;
    $publicPath = $publicPathResolver($filename);
    $currentPath = $currentPath !== null ? ltrim((string)$currentPath, '/') : null;

    if (file_exists($target) && $currentPath !== $publicPath) {
        throw new RuntimeException('Ekziston tashmë një imazh me këtë emër. Fshi imazhin e palidhur ose ndrysho emrin përpara ngarkimit.');
    }

    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Imazhi nuk u ruajt dot në server.');
    }

    chmod($target, 0644);

    return $publicPath;
}

function handle_product_image_upload(string $fieldName, string $baseName, ?string $currentPath = null): ?string
{
    return handle_image_upload(
        $fieldName,
        $baseName,
        product_upload_dir(),
        'public_product_upload_path',
        PRODUCT_UPLOAD_MAX_BYTES,
        $currentPath
    );
}

function handle_category_icon_upload(string $fieldName, string $baseName, ?string $currentPath = null): ?string
{
    return handle_image_upload(
        $fieldName,
        $baseName,
        category_upload_dir(),
        'public_category_upload_path',
        CATEGORY_UPLOAD_MAX_BYTES,
        $currentPath
    );
}

function ensure_category_icon_image_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM categories LIKE 'icon_image_path'");
    $exists = $stmt !== false && $stmt->fetch() !== false;

    if ($exists) {
        return;
    }

    $pdo->exec("ALTER TABLE categories ADD COLUMN icon_image_path VARCHAR(255) NULL AFTER icon");
}
