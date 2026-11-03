<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;

final class LegacyMigrationBackup
{
    public static function snapshot(PDO $db): array
    {
        $result = [];
        $tables = $db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);
        foreach ($tables as $table) {
            $name = str_replace('`', '``', $table);
            $rows = [];
            foreach ($db->query("SELECT * FROM `$name`") as $row) {
                ksort($row);
                $rows[] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            sort($rows, SORT_STRING);
            $definition = $db->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM);
            $schema = $definition[1];
            // MySQL may render an inherited charset explicitly after restoring the same collation.
            if (preg_match('/DEFAULT CHARSET=([a-z0-9_]+) COLLATE=([a-z0-9_]+)/i', $schema, $defaults)) $schema = str_replace(' CHARACTER SET ' . $defaults[1] . ' COLLATE ' . $defaults[2], ' COLLATE ' . $defaults[2], $schema);
            $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows)), 'schema_sha256' => hash('sha256', $schema)];
        }
        return $result;
    }

    public static function create(PDO $db, string $directory): array
    {
        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new RuntimeException('Nama database backup tidak valid.');
        $storage = realpath(BASE_PATH . '/storage');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Folder backup tidak dapat dibuat.');
        $directory = realpath($directory);
        if (!$directory || !$storage || !str_starts_with(strtolower($directory . DIRECTORY_SEPARATOR), strtolower($storage . DIRECTORY_SEPARATOR))) throw new RuntimeException('Backup harus berada dalam storage privat.');
        $mysql = self::binary('mysql');
        $dump = self::binary('mysqldump');
        $credentials = $directory . '/.mysql-' . bin2hex(random_bytes(8)) . '.cnf';
        $quote = static fn(string $s): string => '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '\\r', '\\n'], $s) . '"';
        $config = "[client]\n";
        foreach (['host' => $_ENV['DB_HOST'] ?? '127.0.0.1', 'port' => $_ENV['DB_PORT'] ?? '3306', 'user' => $_ENV['DB_USERNAME'] ?? 'root', 'password' => $_ENV['DB_PASSWORD'] ?? ''] as $key => $value) $config .= $key . '=' . $quote((string)$value) . "\n";
        if (file_put_contents($credentials, $config, LOCK_EX) === false) throw new RuntimeException('Konfigurasi backup sementara gagal dibuat.');
        @chmod($credentials, 0600);
        $file = $directory . '/before-migration.sql';
        if (is_file($file)) { @unlink($credentials); throw new RuntimeException('File backup sudah tersedia; gunakan folder proses baru.'); }
        $verification = 'penggajian_backup_verify_' . bin2hex(random_bytes(6));
        $created = false;
        try {
            $before = self::snapshot($db);
            self::process([$dump, '--defaults-extra-file=' . $credentials, '--single-transaction', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', '--result-file=' . $file, $database], $directory . '/backup-tool.log');
            if (!is_file($file) || filesize($file) === 0) throw new RuntimeException('Backup kosong.');
            // Never allow a single-database dump to switch the restoration target.
            $handle = fopen($file, 'rb');
            if (!$handle) throw new RuntimeException('Backup tidak dapat dibaca.');
            try {
                while (($line = fgets($handle)) !== false) {
                    if (preg_match('/^\s*(?:USE\s|(?:CREATE|DROP|ALTER)\s+DATABASE\s)/i', $line)) throw new RuntimeException('Backup berisi pergantian database; verifikasi dihentikan.');
                }
            } finally { fclose($handle); }
            $db->exec("CREATE DATABASE `$verification` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
            self::process([$mysql, '--defaults-extra-file=' . $credentials, '--binary-mode=1', '--default-character-set=utf8mb4', '--database=' . $verification], $directory . '/backup-restore-tool.log', $file);
            $dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '3306') . ';dbname=' . $verification . ';charset=utf8mb4';
            $restored = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $restored->exec("SET time_zone='+07:00'");
            $restoredSnapshot = self::snapshot($restored);
            $currentSnapshot = self::snapshot($db);
            if ($before !== $restoredSnapshot || $before !== $currentSnapshot) {
                $definitions = [];
                foreach (array_keys($before) as $table) if (($before[$table]['schema_sha256'] ?? '') !== ($restoredSnapshot[$table]['schema_sha256'] ?? '')) {
                    $quoted = str_replace('`', '``', $table);
                    $definitions[$table] = ['before'=>$db->query("SHOW CREATE TABLE `$quoted`")->fetch(PDO::FETCH_NUM)[1], 'restored'=>$restored->query("SHOW CREATE TABLE `$quoted`")->fetch(PDO::FETCH_NUM)[1]];
                }
                file_put_contents($directory . '/backup-mismatch.json', json_encode(['before'=>$before,'restored'=>$restoredSnapshot,'current'=>$currentSnapshot,'definitions'=>$definitions], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                throw new RuntimeException('Hasil pemulihan backup berbeda atau database berubah saat pencadangan. Migrasi belum dijalankan.');
            }
            $result = ['database' => $database, 'file' => basename($file), 'sha256' => hash_file('sha256', $file), 'restoration_verified' => true, 'tables' => $before];
            file_put_contents($directory . '/backup-verification.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return $result;
        } finally {
            if ($created && preg_match('/^penggajian_backup_verify_[a-f0-9]{12}$/D', $verification)) $db->exec("DROP DATABASE `$verification`");
            if (is_file($credentials)) unlink($credentials);
        }
    }

    private static function binary(string $name): string
    {
        $extension = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $candidates = [];
        if (!empty($_ENV['MYSQL_BIN'])) $candidates[] = rtrim($_ENV['MYSQL_BIN'], '/\\') . '/' . $name . $extension;
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $path) $candidates[] = rtrim($path, '/\\') . '/' . $name . $extension;
        foreach (glob(dirname(BASE_PATH, 2) . '/bin/mysql/*/bin/' . $name . $extension) ?: [] as $path) $candidates[] = $path;
        foreach ($candidates as $candidate) if (is_file($candidate)) return $candidate;
        throw new RuntimeException("$name tidak ditemukan; atur MYSQL_BIN ke folder bin MySQL.");
    }

    private static function process(array $command, string $log, ?string $input = null): void
    {
        $descriptors = [0 => $input ? ['file', $input, 'rb'] : ['pipe', 'r'], 1 => ['file', $log, 'ab'], 2 => ['file', $log, 'ab']];
        $process = proc_open($command, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Proses backup tidak dapat dijalankan.');
        if (!$input) fclose($pipes[0]);
        if (proc_close($process) !== 0) throw new RuntimeException('Pencadangan/pemulihan MySQL gagal; rincian tersedia pada log privat.');
    }
}
