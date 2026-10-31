<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Database;
use App\Domain\PayrollFields;

$server = Database::connection(false);
$name = $_ENV['DB_DATABASE'] ?? 'penggajian';
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = Database::connection();
$db->exec(file_get_contents(BASE_PATH . '/database/schema.sql'));
foreach (['tunj_harian','tunj_konsumsi','tunj_lembur','tunj_infal','tunj_overtime'] as $table) {
    $db->exec("CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kd_bagian VARCHAR(5) NOT NULL,jumlah DECIMAL(18,0) NULL DEFAULT 0,UNIQUE KEY uq_bagian(kd_bagian)) ENGINE=InnoDB");
}
foreach (['p_anak','p_bjbs','p_bank','p_lunak','p_lain','tabungan'] as $table) {
    $db->exec("CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nip VARCHAR(15) NOT NULL,jumlah DECIMAL(18,0) NULL DEFAULT 0,UNIQUE KEY uq_nip(nip)) ENGINE=InnoDB");
}
$numeric = implode(',', array_map(static fn($f)=>"`$f` DECIMAL(18,0) NULL DEFAULT 0",array_keys(PayrollFields::numeric())));
$db->exec("CREATE TABLE IF NOT EXISTS penghasilan (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nip VARCHAR(15) NOT NULL,bulan CHAR(2) NOT NULL,tahun CHAR(4) NOT NULL,$numeric,source VARCHAR(20) NOT NULL DEFAULT 'manual',employee_name VARCHAR(150) NULL,department_code VARCHAR(5) NULL,department_name VARCHAR(100) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY ix_period(tahun,bulan),KEY ix_employee_period(nip,tahun,bulan)) ENGINE=InnoDB");
require __DIR__.'/migrate-accounting.php';
echo "Database $name siap. Buka aplikasi untuk membuat akun pertama.\n";
