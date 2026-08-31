<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();
$errors = [];

function backup_internal_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('U gjet një identifier jo i vlefshëm në databazë.');
    }

    return '`' . str_replace('`', '``', $name) . '`';
}

function backup_export_identifier(string $name, bool $backquotes): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('U gjet një identifier jo i vlefshëm në databazë.');
    }

    return $backquotes ? '`' . str_replace('`', '``', $name) . '`' : $name;
}

function backup_tables(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, CREATE_TIME, UPDATE_TIME, CHECK_TIME
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );

    $tables = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)$row['TABLE_NAME'];
        $tables[$name] = [
            'name' => $name,
            'rows' => (int)($row['TABLE_ROWS'] ?? 0),
            'bytes' => (int)($row['DATA_LENGTH'] ?? 0) + (int)($row['INDEX_LENGTH'] ?? 0),
            'create_time' => $row['CREATE_TIME'] ?? null,
            'update_time' => $row['UPDATE_TIME'] ?? null,
            'check_time' => $row['CHECK_TIME'] ?? null,
        ];
    }

    return $tables;
}

function backup_admin_password_valid(PDO $pdo, int $adminId, string $password): bool
{
    $stmt = $pdo->prepare(
        'SELECT password_hash FROM admins WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $hash = $stmt->fetchColumn();

    return $hash !== false && password_verify($password, (string)$hash);
}

function backup_columns(PDO $pdo, string $table): array
{
    $queryTable = backup_internal_identifier($table);
    $stmt = $pdo->query("SHOW FULL COLUMNS FROM {$queryTable}");
    $columns = [];

    foreach ($stmt->fetchAll() as $row) {
        $columns[(string)$row['Field']] = [
            'name' => (string)$row['Field'],
            'type' => strtolower((string)$row['Type']),
        ];
    }

    return $columns;
}

function backup_is_binary_type(string $type): bool
{
    return (bool)preg_match('/(?:^|\b)(?:binary|varbinary|tinyblob|blob|mediumblob|longblob)(?:\b|\()/i', $type);
}

function backup_is_numeric_type(string $type): bool
{
    return (bool)preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit|bool|boolean)\b/i', $type);
}

function backup_sql_value(PDO $pdo, mixed $value, array $column, bool $binaryHex): string
{
    if ($value === null) {
        return 'NULL';
    }

    $type = (string)($column['type'] ?? '');

    if ($binaryHex && backup_is_binary_type($type)) {
        return '0x' . bin2hex((string)$value);
    }

    if (backup_is_numeric_type($type) && is_numeric((string)$value)) {
        return (string)$value;
    }

    $quoted = $pdo->quote((string)$value);
    if ($quoted === false) {
        throw new RuntimeException('Një vlerë e databazës nuk u quote-ua dot.');
    }

    return $quoted;
}

function backup_create_table_sql(
    PDO $pdo,
    string $table,
    bool $ifNotExists,
    bool $includeAutoIncrement,
    bool $backquotes
): string {
    $queryTable = backup_internal_identifier($table);
    $stmt = $pdo->query("SHOW CREATE TABLE {$queryTable}");
    $row = $stmt->fetch(PDO::FETCH_NUM);

    if (!$row || !isset($row[1])) {
        throw new RuntimeException("Struktura e tabelës {$table} nuk u lexua dot.");
    }

    $sql = (string)$row[1];

    if ($ifNotExists) {
        $sql = preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1) ?? $sql;
    }

    if (!$includeAutoIncrement) {
        $sql = preg_replace('/\sAUTO_INCREMENT=\d+/i', '', $sql) ?? $sql;
    }

    if (!$backquotes) {
        $sql = str_replace('`', '', $sql);
    }

    return $sql;
}

function backup_write($handle, string $text): void
{
    $length = strlen($text);
    $offset = 0;

    while ($offset < $length) {
        $written = fwrite($handle, substr($text, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Backup SQL nuk u shkrua dot në file-in temporar.');
        }
        $offset += $written;
    }
}

function backup_write_comments($handle, array $options, string $databaseName, string $serverVersion): void
{
    if (!$options['comments']) {
        return;
    }

    backup_write($handle, "-- Bar Tadeo database backup\n");
    backup_write($handle, '-- Database: ' . $databaseName . "\n");
    backup_write($handle, '-- Generated: ' . date('Y-m-d H:i:s T') . "\n");
    backup_write($handle, '-- Server version: ' . $serverVersion . "\n");
    backup_write($handle, '-- PHP version: ' . PHP_VERSION . "\n");

    $custom = trim((string)$options['header_comment']);
    if ($custom !== '') {
        $custom = str_replace(["\r\n", "\r"], "\n", $custom);
        foreach (explode("\n", $custom) as $line) {
            backup_write($handle, '-- ' . str_replace("\0", '', $line) . "\n");
        }
    }

    backup_write($handle, "\n");
}

function backup_insert_keyword(string $mode): string
{
    return match ($mode) {
        'insert_ignore' => 'INSERT IGNORE',
        'replace' => 'REPLACE',
        default => 'INSERT',
    };
}

function backup_dump_table_data(
    PDO $pdo,
    $handle,
    string $table,
    array $options
): void {
    $queryTable = backup_internal_identifier($table);
    $exportTable = backup_export_identifier($table, $options['backquotes']);
    $columns = backup_columns($pdo, $table);

    if ($columns === []) {
        return;
    }

    $columnNames = array_keys($columns);
    $exportColumnList = array_map(
        fn(string $column): string => backup_export_identifier($column, $options['backquotes']),
        $columnNames
    );

    if ($options['truncate']) {
        backup_write($handle, "TRUNCATE TABLE {$exportTable};\n");
    }

    if ($options['lock_tables']) {
        backup_write($handle, "LOCK TABLES {$exportTable} WRITE;\n");
    }

    $stmt = $pdo->query("SELECT * FROM {$queryTable}");
    $keyword = backup_insert_keyword($options['insert_mode']);
    $columnSql = $options['column_names'] ? ' (' . implode(', ', $exportColumnList) . ')' : '';
    $prefix = "{$keyword} INTO {$exportTable}{$columnSql} VALUES ";
    $maxLength = max(4096, min(5000000, (int)$options['max_query_length']));

    $batch = [];
    $batchLength = strlen($prefix) + 2;

    $flush = static function () use (&$batch, &$batchLength, $handle, $prefix): void {
        if ($batch === []) {
            return;
        }

        backup_write($handle, $prefix . implode(",\n", $batch) . ";\n");
        $batch = [];
        $batchLength = strlen($prefix) + 2;
    };

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = [];
        foreach ($columnNames as $columnName) {
            $values[] = backup_sql_value(
                $pdo,
                $row[$columnName] ?? null,
                $columns[$columnName],
                $options['binary_hex']
            );
        }

        $tuple = '(' . implode(', ', $values) . ')';

        if (!$options['multiple_rows']) {
            backup_write($handle, $prefix . $tuple . ";\n");
            continue;
        }

        $additional = strlen($tuple) + ($batch === [] ? 0 : 2);
        if ($batch !== [] && ($batchLength + $additional) > $maxLength) {
            $flush();
        }

        $batch[] = $tuple;
        $batchLength += $additional;
    }

    $flush();

    if ($options['lock_tables']) {
        backup_write($handle, "UNLOCK TABLES;\n");
    }

    backup_write($handle, "\n");
}

function backup_generate_sql(PDO $pdo, array $tables, array $selectedStructure, array $selectedData, array $options): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'tadeo-db-');
    if ($tmp === false) {
        throw new RuntimeException('Serveri nuk krijoi dot file temporar për backup.');
    }

    @chmod($tmp, 0600);
    $handle = fopen($tmp, 'wb');
    if ($handle === false) {
        @unlink($tmp);
        throw new RuntimeException('File-i temporar i backup-it nuk u hap dot.');
    }

    $databaseName = defined('DB_NAME') ? (string)DB_NAME : (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $serverVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $oldTimezone = null;
    $sourceTransaction = false;

    try {
        if ($options['timestamp_utc']) {
            $oldTimezone = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
            $pdo->exec("SET time_zone = '+00:00'");
        }

        if ($options['transaction']) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $sourceTransaction = true;
        }

        backup_write_comments($handle, $options, $databaseName, $serverVersion);
        backup_write($handle, "SET NAMES utf8mb4;\n");

        if ($options['disable_fk']) {
            backup_write($handle, "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;\nSET FOREIGN_KEY_CHECKS=0;\n");
        }

        if ($options['timestamp_utc']) {
            backup_write($handle, "SET @OLD_TIME_ZONE=@@TIME_ZONE;\nSET TIME_ZONE='+00:00';\n");
        }

        if ($options['transaction']) {
            backup_write($handle, "SET AUTOCOMMIT=0;\nSTART TRANSACTION;\n");
        }

        if ($options['create_database']) {
            $dbExport = backup_export_identifier($databaseName, $options['backquotes']);
            backup_write(
                $handle,
                "CREATE DATABASE IF NOT EXISTS {$dbExport} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE {$dbExport};\n"
            );
        }

        backup_write($handle, "\n");

        foreach ($tables as $tableName => $meta) {
            $includeStructure = in_array($tableName, $selectedStructure, true);
            $includeData = in_array($tableName, $selectedData, true);

            if (!$includeStructure && !$includeData) {
                continue;
            }

            if ($options['comments']) {
                backup_write($handle, "-- --------------------------------------------------------\n");
                backup_write($handle, "-- Table: {$tableName}\n");
                if ($options['table_timestamps']) {
                    backup_write($handle, '-- Created: ' . ((string)($meta['create_time'] ?? '') ?: 'n/a') . "\n");
                    backup_write($handle, '-- Updated: ' . ((string)($meta['update_time'] ?? '') ?: 'n/a') . "\n");
                    backup_write($handle, '-- Checked: ' . ((string)($meta['check_time'] ?? '') ?: 'n/a') . "\n");
                }
                backup_write($handle, "\n");
            }

            $exportTable = backup_export_identifier($tableName, $options['backquotes']);

            if ($includeStructure) {
                if ($options['drop_table']) {
                    backup_write($handle, "DROP TABLE IF EXISTS {$exportTable};\n");
                }

                if ($options['create_table']) {
                    backup_write(
                        $handle,
                        backup_create_table_sql(
                            $pdo,
                            $tableName,
                            $options['if_not_exists'],
                            $options['auto_increment'],
                            $options['backquotes']
                        ) . ";\n\n"
                    );
                }
            }

            if ($includeData) {
                $skipBytes = (float)$options['skip_larger_mib'] * 1024 * 1024;
                if ($skipBytes > 0 && (int)$meta['bytes'] > $skipBytes) {
                    backup_write(
                        $handle,
                        '-- Data skipped because estimated table size exceeds ' . $options['skip_larger_mib'] . " MiB.\n\n"
                    );
                } else {
                    backup_dump_table_data($pdo, $handle, $tableName, $options);
                }
            }
        }

        if ($options['transaction']) {
            backup_write($handle, "COMMIT;\nSET AUTOCOMMIT=1;\n");
        }

        if ($options['timestamp_utc']) {
            backup_write($handle, "SET TIME_ZONE=@OLD_TIME_ZONE;\n");
        }

        if ($options['disable_fk']) {
            backup_write($handle, "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n");
        }

        if ($sourceTransaction && $pdo->inTransaction()) {
            $pdo->commit();
            $sourceTransaction = false;
        }
    } catch (Throwable $e) {
        if ($sourceTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($handle);
        @unlink($tmp);

        if ($oldTimezone !== null) {
            try {
                $quotedTimezone = $pdo->quote($oldTimezone);
                if ($quotedTimezone !== false) {
                    $pdo->exec('SET time_zone = ' . $quotedTimezone);
                }
            } catch (Throwable $ignored) {
            }
        }

        throw $e;
    }

    fclose($handle);

    if ($oldTimezone !== null) {
        try {
            $quotedTimezone = $pdo->quote($oldTimezone);
            if ($quotedTimezone !== false) {
                $pdo->exec('SET time_zone = ' . $quotedTimezone);
            }
        } catch (Throwable $ignored) {
        }
    }

    return $tmp;
}

function backup_gzip_file(string $sourcePath): string
{
    if (!function_exists('gzopen')) {
        throw new RuntimeException('GZIP nuk mbështetet nga PHP në këtë server.');
    }

    $targetPath = tempnam(sys_get_temp_dir(), 'tadeo-gz-');
    if ($targetPath === false) {
        throw new RuntimeException('Serveri nuk krijoi dot file temporar GZIP.');
    }

    @chmod($targetPath, 0600);
    $in = fopen($sourcePath, 'rb');
    $out = gzopen($targetPath, 'wb9');

    if ($in === false || $out === false) {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            gzclose($out);
        }
        @unlink($targetPath);
        throw new RuntimeException('GZIP backup nuk u krijua dot.');
    }

    try {
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('SQL backup nuk u lexua dot për GZIP.');
            }
            if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                throw new RuntimeException('GZIP backup nuk u shkrua dot.');
            }
        }
    } catch (Throwable $e) {
        fclose($in);
        gzclose($out);
        @unlink($targetPath);
        throw $e;
    }

    fclose($in);
    gzclose($out);

    return $targetPath;
}

function backup_zip_file(string $sourcePath, string $entryName): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP nuk mbështetet nga PHP në këtë server. Përdor GZIP ose SQL.');
    }

    $targetPath = tempnam(sys_get_temp_dir(), 'tadeo-zip-');
    if ($targetPath === false) {
        throw new RuntimeException('Serveri nuk krijoi dot file temporar ZIP.');
    }

    @unlink($targetPath);
    $zip = new ZipArchive();
    $opened = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException('ZIP backup nuk u hap dot për shkrim.');
    }

    if (!$zip->addFile($sourcePath, $entryName)) {
        $zip->close();
        @unlink($targetPath);
        throw new RuntimeException('SQL backup nuk u shtua dot në ZIP.');
    }

    $zip->close();
    @chmod($targetPath, 0600);

    return $targetPath;
}

function backup_send_download(string $path, string $filename, string $contentType, array $cleanupPaths): never
{
    if (!is_file($path)) {
        throw new RuntimeException('File-i final i backup-it mungon.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\\\"") . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('File-i final i backup-it nuk u hap dot.');
    }

    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
    }
    fclose($handle);

    foreach (array_unique($cleanupPaths) as $cleanupPath) {
        if (is_string($cleanupPath) && $cleanupPath !== '' && is_file($cleanupPath)) {
            @unlink($cleanupPath);
        }
    }

    exit;
}

$tables = backup_tables($pdo);
$tableNames = array_keys($tables);
$gzipSupported = function_exists('gzopen');
$zipSupported = class_exists('ZipArchive');

$defaults = [
    'comments' => true,
    'table_timestamps' => true,
    'transaction' => true,
    'disable_fk' => true,
    'lock_tables' => false,
    'create_database' => false,
    'drop_table' => true,
    'create_table' => true,
    'if_not_exists' => false,
    'auto_increment' => true,
    'backquotes' => true,
    'truncate' => false,
    'column_names' => true,
    'multiple_rows' => true,
    'binary_hex' => true,
    'timestamp_utc' => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } elseif (!backup_admin_password_valid($pdo, (int)$admin['id'], (string)($_POST['current_password'] ?? ''))) {
        $errors[] = 'Password-i aktual i adminit nuk është i saktë.';
    } else {
        $selectedStructure = array_values(array_intersect(
            $tableNames,
            array_map('strval', (array)($_POST['structure'] ?? []))
        ));
        $selectedData = array_values(array_intersect(
            $tableNames,
            array_map('strval', (array)($_POST['data'] ?? []))
        ));

        if ($selectedStructure === [] && $selectedData === []) {
            $errors[] = 'Zgjidh të paktën Structure ose Data për një tabelë.';
        }

        $compression = (string)($_POST['compression'] ?? 'gzip');
        if (!in_array($compression, ['sql', 'gzip', 'zip'], true)) {
            $compression = 'gzip';
        }
        if ($compression === 'gzip' && !$gzipSupported) {
            $errors[] = 'GZIP nuk mbështetet nga PHP në këtë server.';
        }
        if ($compression === 'zip' && !$zipSupported) {
            $errors[] = 'ZIP nuk mbështetet nga PHP në këtë server. Përdor GZIP ose SQL.';
        }

        $options = [];
        foreach ($defaults as $key => $default) {
            $options[$key] = isset($_POST[$key]);
        }
        $options['header_comment'] = trim((string)($_POST['header_comment'] ?? ''));
        $options['insert_mode'] = (string)($_POST['insert_mode'] ?? 'insert');
        if (!in_array($options['insert_mode'], ['insert', 'insert_ignore', 'replace'], true)) {
            $options['insert_mode'] = 'insert';
        }
        $options['max_query_length'] = max(4096, min(5000000, (int)($_POST['max_query_length'] ?? 1000000)));
        $options['skip_larger_mib'] = max(0, min(10240, (float)($_POST['skip_larger_mib'] ?? 0)));

        if (!$errors) {
            @set_time_limit(0);
            session_write_close();
            $sqlPath = '';
            $finalPath = '';

            try {
                $sqlPath = backup_generate_sql(
                    $pdo,
                    $tables,
                    $selectedStructure,
                    $selectedData,
                    $options
                );

                $safeDb = preg_replace('/[^A-Za-z0-9_-]+/', '-', defined('DB_NAME') ? (string)DB_NAME : 'tadeobar') ?: 'tadeobar';
                $baseName = $safeDb . '-' . date('Ymd-His') . '-full-backup.sql';

                if ($compression === 'gzip') {
                    $finalPath = backup_gzip_file($sqlPath);
                    backup_send_download(
                        $finalPath,
                        $baseName . '.gz',
                        'application/gzip',
                        [$sqlPath, $finalPath]
                    );
                }

                if ($compression === 'zip') {
                    $finalPath = backup_zip_file($sqlPath, $baseName);
                    backup_send_download(
                        $finalPath,
                        preg_replace('/\.sql$/', '', $baseName) . '.zip',
                        'application/zip',
                        [$sqlPath, $finalPath]
                    );
                }

                backup_send_download(
                    $sqlPath,
                    $baseName,
                    'application/sql; charset=utf-8',
                    [$sqlPath]
                );
            } catch (Throwable $e) {
                if ($sqlPath !== '' && is_file($sqlPath)) {
                    @unlink($sqlPath);
                }
                if ($finalPath !== '' && is_file($finalPath)) {
                    @unlink($finalPath);
                }
                $errors[] = 'Backup dështoi: ' . $e->getMessage();
                admin_session_start();
            }
        }
    }
}

$selectedStructureForForm = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_map('strval', (array)($_POST['structure'] ?? []))
    : $tableNames;
$selectedDataForForm = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_map('strval', (array)($_POST['data'] ?? []))
    : $tableNames;
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Database Backup | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'settings'); ?>

        <main>
            <h1 class="admin-title">Database Backup</h1>
            <p class="admin-muted">
                Eksporto databazën live në SQL, GZIP ose ZIP. Backup-i gjenerohet vetëm pas verifikimit të password-it aktual të adminit.
            </p>

            <?php foreach ($errors as $error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <form class="form-card" method="post" autocomplete="off">
                <?= csrf_field() ?>

                <div class="msg">
                    Default-et janë vendosur për një full backup të sigurt: të gjitha tabelat, Structure + Data, DROP + CREATE TABLE,
                    transaction, foreign-key checks off gjatë restore-it, AUTO_INCREMENT dhe INSERT me column names + multiple rows.
                </div>

                <h2>Tabelat</h2>
                <?php foreach ($tables as $table): ?>
                    <div class="panel">
                        <strong><?= e($table['name']) ?></strong>
                        <div class="help-text">
                            ~<?= e(number_format($table['bytes'] / 1048576, 2)) ?> MiB · ~<?= e(number_format($table['rows'])) ?> rows
                        </div>

                        <label class="checkbox-row">
                            <input
                                type="checkbox"
                                name="structure[]"
                                value="<?= e($table['name']) ?>"
                                <?= in_array($table['name'], $selectedStructureForForm, true) ? 'checked' : '' ?>
                            >
                            Structure
                        </label>

                        <label class="checkbox-row">
                            <input
                                type="checkbox"
                                name="data[]"
                                value="<?= e($table['name']) ?>"
                                <?= in_array($table['name'], $selectedDataForForm, true) ? 'checked' : '' ?>
                            >
                            Data
                        </label>
                    </div>
                <?php endforeach; ?>

                <h2>Output</h2>
                <label>Compression</label>
                <select name="compression">
                    <option value="gzip" <?= ((string)($_POST['compression'] ?? 'gzip') === 'gzip') ? 'selected' : '' ?> <?= !$gzipSupported ? 'disabled' : '' ?>>
                        GZIP (.sql.gz)<?= !$gzipSupported ? ' — unavailable' : '' ?>
                    </option>
                    <option value="zip" <?= ((string)($_POST['compression'] ?? '') === 'zip') ? 'selected' : '' ?> <?= !$zipSupported ? 'disabled' : '' ?>>
                        ZIP (.zip)<?= !$zipSupported ? ' — unavailable' : '' ?>
                    </option>
                    <option value="sql" <?= ((string)($_POST['compression'] ?? '') === 'sql') ? 'selected' : '' ?>>SQL (.sql)</option>
                </select>

                <label>Skip data for tables larger than (MiB)</label>
                <input
                    name="skip_larger_mib"
                    type="number"
                    min="0"
                    max="10240"
                    step="0.1"
                    value="<?= e((string)($_POST['skip_larger_mib'] ?? '0')) ?>"
                >
                <div class="help-text">0 = mos anashkalo asnjë tabelë. Structure ruhet edhe kur Data anashkalohet.</div>

                <label class="checkbox-row">
                    <input type="checkbox" name="comments" <?= isset($_POST['comments']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Display comments
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="table_timestamps" <?= isset($_POST['table_timestamps']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Include table created / updated / checked timestamps
                </label>

                <label>Additional custom header comment</label>
                <input name="header_comment" value="<?= e((string)($_POST['header_comment'] ?? 'Bar Tadeo full database backup')) ?>">

                <h2>Format-specific options</h2>
                <label class="checkbox-row">
                    <input type="checkbox" name="transaction" <?= isset($_POST['transaction']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Enclose export in a transaction + consistent source snapshot
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="disable_fk" <?= isset($_POST['disable_fk']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Disable foreign key checks during restore
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="lock_tables" <?= isset($_POST['lock_tables']) ? 'checked' : '' ?>>
                    Add LOCK TABLES / UNLOCK TABLES statements
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="timestamp_utc" <?= isset($_POST['timestamp_utc']) ? 'checked' : '' ?>>
                    Dump TIMESTAMP values in UTC
                </label>

                <h2>Object creation options</h2>
                <label class="checkbox-row">
                    <input type="checkbox" name="create_database" <?= isset($_POST['create_database']) ? 'checked' : '' ?>>
                    Add CREATE DATABASE / USE statement
                </label>
                <div class="help-text">Default OFF: InfinityFree zakonisht menaxhon krijimin e databazës nga control panel.</div>

                <label class="checkbox-row">
                    <input type="checkbox" name="drop_table" <?= isset($_POST['drop_table']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Add DROP TABLE IF EXISTS statement
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="create_table" <?= isset($_POST['create_table']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Add CREATE TABLE statement
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="if_not_exists" <?= isset($_POST['if_not_exists']) ? 'checked' : '' ?>>
                    Add IF NOT EXISTS to CREATE TABLE
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="auto_increment" <?= isset($_POST['auto_increment']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Include current AUTO_INCREMENT value
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="backquotes" <?= isset($_POST['backquotes']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Enclose table and column names with backquotes
                </label>

                <h2>Data creation options</h2>
                <label class="checkbox-row">
                    <input type="checkbox" name="truncate" <?= isset($_POST['truncate']) ? 'checked' : '' ?>>
                    TRUNCATE TABLE before INSERT
                </label>

                <label>Insert mode</label>
                <select name="insert_mode">
                    <?php $insertMode = (string)($_POST['insert_mode'] ?? 'insert'); ?>
                    <option value="insert" <?= $insertMode === 'insert' ? 'selected' : '' ?>>INSERT</option>
                    <option value="insert_ignore" <?= $insertMode === 'insert_ignore' ? 'selected' : '' ?>>INSERT IGNORE</option>
                    <option value="replace" <?= $insertMode === 'replace' ? 'selected' : '' ?>>REPLACE</option>
                </select>

                <label class="checkbox-row">
                    <input type="checkbox" name="column_names" <?= isset($_POST['column_names']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Include column names in every INSERT statement
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="multiple_rows" <?= isset($_POST['multiple_rows']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Insert multiple rows in every INSERT statement
                </label>

                <label>Maximal length of created INSERT query (bytes)</label>
                <input
                    name="max_query_length"
                    type="number"
                    min="4096"
                    max="5000000"
                    step="1024"
                    value="<?= e((string)($_POST['max_query_length'] ?? '1000000')) ?>"
                >

                <label class="checkbox-row">
                    <input type="checkbox" name="binary_hex" <?= isset($_POST['binary_hex']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                    Dump binary columns in hexadecimal notation
                </label>

                <h2>Konfirmimi</h2>
                <label>Password aktual i adminit</label>
                <input name="current_password" type="password" autocomplete="current-password" required>
                <div class="help-text">
                    Kërkohet në çdo export. Backup-i mund të përmbajë admin password hash, settings, analytics dhe të dhëna private.
                </div>

                <button type="submit">Gjenero dhe shkarko backup-in</button>
            </form>
        </main>
    </div>
</body>
</html>
