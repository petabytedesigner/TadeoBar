<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const PRODUCT_UPLOAD_MAX_BYTES = 10485760; // 10 MB source file
const CATEGORY_UPLOAD_MAX_BYTES = 10485760; // 10 MB source file

const PRODUCT_FINAL_WEBP_MAX_BYTES = 512000; // 500 KB final product image
const CATEGORY_FINAL_WEBP_MAX_BYTES = 716800; // 700 KB final category image

const PRODUCT_TARGET_WIDTH = 1080;
const PRODUCT_TARGET_HEIGHT = 1920;
const CATEGORY_MAX_DIMENSION = 1600;

const PRODUCT_RATIO_WIDTH = 9;
const PRODUCT_RATIO_HEIGHT = 16;
const PRODUCT_RATIO_TOLERANCE = 0.02; // 2%

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

function upload_supported_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function upload_can_process_mime(string $mime): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }

    return match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg'),
        'image/png' => function_exists('imagecreatefrompng'),
        'image/webp' => function_exists('imagecreatefromwebp'),
        default => false,
    };
}

function upload_create_image(string $tmpName, string $mime)
{
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmpName),
        'image/png' => @imagecreatefrompng($tmpName),
        'image/webp' => @imagecreatefromwebp($tmpName),
        default => false,
    };
}

function upload_image_dimensions(string $tmpName): array
{
    $info = @getimagesize($tmpName);

    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('Dimensionet e imazhit nuk u lexuan dot.');
    }

    return [(int)$info[0], (int)$info[1]];
}

function upload_validate_product_ratio(int $width, int $height): void
{
    if ($width <= 0 || $height <= 0) {
        throw new RuntimeException('Dimensionet e imazhit nuk janë të vlefshme.');
    }

    $actualRatio = $width / $height;
    $targetRatio = PRODUCT_RATIO_WIDTH / PRODUCT_RATIO_HEIGHT;
    $difference = abs($actualRatio - $targetRatio) / $targetRatio;

    if ($difference > PRODUCT_RATIO_TOLERANCE) {
        throw new RuntimeException(
            'Imazhi i produktit duhet të jetë patjetër në format 9:16, p.sh. 1080×1920. ' .
            'Ky format siguron që produkti të shfaqet mirë në telefon dhe PC.'
        );
    }
}

function upload_resize_image($source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight)
{
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

    if (!$canvas) {
        throw new RuntimeException('Imazhi nuk u përgatit dot për optimizim.');
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

    imagecopyresampled(
        $canvas,
        $source,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    return $canvas;
}

function upload_resize_to_max_dimension($source, int $sourceWidth, int $sourceHeight, int $maxDimension): array
{
    $largest = max($sourceWidth, $sourceHeight);

    if ($largest <= $maxDimension) {
        return [$source, $sourceWidth, $sourceHeight, false];
    }

    $scale = $maxDimension / $largest;
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));

    $resized = upload_resize_image($source, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight);

    return [$resized, $targetWidth, $targetHeight, true];
}

function upload_save_webp_under_limit($image, string $target, int $maxBytes): bool
{
    $qualities = [85, 82, 78, 74, 70, 66, 62, 58];

    foreach ($qualities as $quality) {
        if (is_file($target)) {
            @unlink($target);
        }

        $saved = @imagewebp($image, $target, $quality);

        if ($saved === true && is_file($target) && filesize($target) <= $maxBytes) {
            return true;
        }
    }

    return is_file($target) && filesize($target) <= $maxBytes;
}

function upload_optimize_to_webp(
    string $tmpName,
    string $mime,
    string $target,
    bool $isProduct
): void {
    if (!upload_can_process_mime($mime)) {
        throw new RuntimeException('Serveri nuk mbështet përpunimin WEBP/JPG/PNG për këtë upload.');
    }

    [$sourceWidth, $sourceHeight] = upload_image_dimensions($tmpName);

    if ($isProduct) {
        upload_validate_product_ratio($sourceWidth, $sourceHeight);
    }

    $pixelCount = $sourceWidth * $sourceHeight;
    if ($pixelCount > 30000000) {
        throw new RuntimeException('Imazhi është shumë i madh në dimensione. Përdor një imazh më të vogël.');
    }

    $source = upload_create_image($tmpName, $mime);

    if (!$source) {
        throw new RuntimeException('Imazhi nuk u hap dot për optimizim.');
    }

    if ($mime === 'image/png' || $mime === 'image/webp') {
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($source);
        }

        @imagealphablending($source, true);
        @imagesavealpha($source, true);
    }

    if ($isProduct) {
        $finalImage = upload_resize_image(
            $source,
            $sourceWidth,
            $sourceHeight,
            PRODUCT_TARGET_WIDTH,
            PRODUCT_TARGET_HEIGHT
        );
        $finalMaxBytes = PRODUCT_FINAL_WEBP_MAX_BYTES;
        imagedestroy($source);
    } else {
        [$finalImage, , , $resized] = upload_resize_to_max_dimension(
            $source,
            $sourceWidth,
            $sourceHeight,
            CATEGORY_MAX_DIMENSION
        );
        $finalMaxBytes = CATEGORY_FINAL_WEBP_MAX_BYTES;

        if ($resized) {
            imagedestroy($source);
        }
    }

    $saved = upload_save_webp_under_limit($finalImage, $target, $finalMaxBytes);
    imagedestroy($finalImage);

    if (!$saved) {
        @unlink($target);
        throw new RuntimeException('Imazhi nuk u optimizua dot brenda madhësisë së lejuar.');
    }
}

function handle_image_upload(
    string $fieldName,
    string $baseName,
    string $targetDir,
    callable $publicPathResolver,
    int $maxBytes,
    ?string $currentPath = null,
    bool $isProduct = false
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
        throw new RuntimeException('Imazhi burim duhet të jetë maksimumi 10 MB.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('File-i i ngarkuar nuk është i vlefshëm.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $allowed = upload_supported_mimes();

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Lejohen vetëm imazhe JPG, PNG ose WEBP.');
    }

    ensure_safe_upload_dir($targetDir);

    $slug = slugify_filename($baseName);
    $filename = $slug . '.webp';
    $target = $targetDir . '/' . $filename;
    $publicPath = $publicPathResolver($filename);
    $currentPath = $currentPath !== null ? ltrim((string)$currentPath, '/') : null;

    if (file_exists($target) && $currentPath !== $publicPath) {
        throw new RuntimeException('Ekziston tashmë një imazh me këtë emër. Fshi imazhin e palidhur ose ndrysho emrin përpara ngarkimit.');
    }

    upload_optimize_to_webp($tmpName, $mime, $target, $isProduct);
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
        $currentPath,
        true
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
        $currentPath,
        false
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
