<?php
declare(strict_types=1);

/*
 * Database backup (pure PHP, no mysqldump needed). Backups are written to APP_ROOT/backups,
 * which is blocked from the web (.htaccess); admins download them through admin/backup_download.php.
 */

function backup_dir(): string
{
    $dir = APP_ROOT . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (!is_file($dir . '/index.html')) {
        file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** Write a full SQL dump (structure + data). Returns the file name (inside backups/). */
function db_backup(string $label = 'manual'): string
{
    @set_time_limit(600);
    $pdo = db();
    $label = preg_replace('/[^a-z0-9_-]/i', '', $label) ?: 'backup';
    $gz = function_exists('gzopen');
    $name = 'xgen-' . $label . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . ($gz ? '.sql.gz' : '.sql');
    $path = backup_dir() . '/' . $name;
    $fh = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
    if (!$fh) {
        throw new RuntimeException('Could not create the backup file. Check that the backups folder is writable.');
    }
    $w = fn(string $s) => $gz ? gzwrite($fh, $s) : fwrite($fh, $s);

    $w("-- XGEN LIFE Intl database backup\n-- Created: " . date('Y-m-d H:i:s') . " ({$label})\n-- Database: " . DB_NAME . "\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table]) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        $w("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
        $st = $pdo->query("SELECT * FROM `{$table}`", PDO::FETCH_NUM);
        $batch = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
            if (count($batch) >= 200) {
                $w("INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            $w("INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $w("\n");
    }
    $w("SET FOREIGN_KEY_CHECKS = 1;\n");
    $gz ? gzclose($fh) : fclose($fh);
    return $name;
}

function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/xgen-*.sql*') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** Resolve a backup name safely (no path traversal). */
function backup_path(string $name): ?string
{
    if (!preg_match('/^xgen-[a-z0-9_-]+-\d{8}-\d{6}-[0-9a-f]{8,16}\.sql(\.gz)?$/i', $name)) {
        return null;
    }
    $p = backup_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return round($n, $i ? 1 : 0) . ' ' . $u[$i];
}
