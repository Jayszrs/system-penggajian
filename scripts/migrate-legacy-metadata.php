<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';

// Only documented source identity fields are added, never source-driven DDL.
$legacyMetadataDb = App\Core\Database::connection();
foreach (['karyawan','tunj_harian','tunj_konsumsi','tunj_lembur','tunj_infal','tunj_overtime','tunj_jam','p_anak','p_bjbs','p_bank','p_lunak','p_lain','tabungan'] as $legacyMetadataTable) {
    $legacyMetadataColumns = $legacyMetadataDb->query("SHOW COLUMNS FROM `$legacyMetadataTable`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('legacy_id', $legacyMetadataColumns, true)) $legacyMetadataDb->exec("ALTER TABLE `$legacyMetadataTable` ADD COLUMN legacy_id DECIMAL(18,0) NULL");
}
