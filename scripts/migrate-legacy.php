<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\{LegacyMigration, LegacyMigrationBackup};

try {
    $file = BASE_PATH . '/storage/legacy/export.json';
    $checkOnly = false;
    $runDirectory = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') $checkOnly = true;
        elseif (str_starts_with($argument, '--database=')) {
            $database = substr($argument, 11);
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new RuntimeException('Nama database tujuan tidak valid.');
            $_ENV['DB_DATABASE'] = $database;
        } elseif (str_starts_with($argument, '--run-dir=')) $runDirectory = substr($argument, 10);
        elseif (str_starts_with($argument, '--')) throw new RuntimeException('Pilihan migrasi tidak dikenal.');
        else $file = $argument;
    }
    if ($file === BASE_PATH . '/storage/legacy/export.json' && !is_file($file)) {
        $exports = glob(BASE_PATH . '/storage/legacy/*/*/export.json') ?: [];
        usort($exports, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        $file = $exports[0] ?? $file;
    }
    if (!is_file($file)) throw new RuntimeException('Ekspor belum tersedia. Jalankan scripts/migrate-legacy.ps1 dengan backup SQL Server.');
    $export = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $db = Database::connection();
    $service = new LegacyMigration($db);
    $service->run($export, false);
    if (!$runDirectory) $runDirectory = BASE_PATH . '/storage/legacy/' . ($export['source_sha256'] ?? 'unknown') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!is_dir($runDirectory) && !mkdir($runDirectory, 0700, true) && !is_dir($runDirectory)) throw new RuntimeException('Folder proses tidak dapat dibuat.');
    $storageRoot = realpath(BASE_PATH . '/storage');
    $resolvedRun = realpath($runDirectory);
    if (!$storageRoot || !$resolvedRun || !str_starts_with(strtolower($resolvedRun . DIRECTORY_SEPARATOR), strtolower($storageRoot . DIRECTORY_SEPARATOR))) throw new RuntimeException('Laporan migrasi harus disimpan dalam storage privat.');
    if (!$checkOnly) {
        LegacyMigrationBackup::create($db, $runDirectory);
        require __DIR__ . '/migrate-legacy-metadata.php';
    }
    $summary = $service->run($export, !$checkOnly);
    file_put_contents($runDirectory . '/reconciliation.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo sprintf("%s: %d tabel, %d baris sumber, %d baris operasional cocok, %d perubahan data, %d masalah.\n", $summary['status'], $summary['tables'], $summary['rows'], $summary['operational_rows'], $summary['data_changes'], count($summary['issues']));
    echo 'Laporan privat: ' . $runDirectory . DIRECTORY_SEPARATOR . "reconciliation.json\n";
    exit($summary['status'] === 'verified' ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migrasi dihentikan: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
