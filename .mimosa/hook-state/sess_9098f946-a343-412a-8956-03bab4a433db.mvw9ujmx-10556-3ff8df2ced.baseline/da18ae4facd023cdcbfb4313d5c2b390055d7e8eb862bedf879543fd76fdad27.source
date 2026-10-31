<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Database;

$server = Database::connection(false);
$name = $_ENV['DB_DATABASE'] ?? 'penggajian';
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('DB_DATABASE hanya boleh berisi huruf, angka, dan underscore.');
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = Database::connection();
foreach (array_filter(array_map('trim', explode(';', file_get_contents(BASE_PATH . '/database/schema.sql')))) as $statement) {
    $db->query($statement);
}
foreach (['tunj_harian','tunj_konsumsi','tunj_lembur','tunj_infal','tunj_overtime'] as $table) {
    $db->exec("CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kd_bagian VARCHAR(5) NOT NULL,jumlah DECIMAL(18,0) NULL DEFAULT 0,UNIQUE KEY uq_bagian(kd_bagian)) ENGINE=InnoDB");
}
foreach (['p_anak','p_bjbs','p_bank','p_lunak','p_lain','tabungan'] as $table) {
    $db->exec("CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nip VARCHAR(15) NOT NULL,jumlah DECIMAL(18,0) NULL DEFAULT 0,UNIQUE KEY uq_nip(nip)) ENGINE=InnoDB");
}
echo "Database $name siap. Buka aplikasi untuk membuat akun pertama.\n";
