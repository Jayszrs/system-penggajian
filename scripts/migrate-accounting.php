<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=App\Core\Database::connection();
$columns=$db->query('SHOW COLUMNS FROM penghasilan')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('calculation_version',$columns,true))$db->exec("ALTER TABLE penghasilan ADD COLUMN calculation_version VARCHAR(24) NOT NULL DEFAULT 'legacy_v1' AFTER source");
if(!in_array('calculation_snapshot',$columns,true))$db->exec('ALTER TABLE penghasilan ADD COLUMN calculation_snapshot JSON NULL AFTER calculation_version');
echo "Metadata kalkulasi siap; nominal transaksi tidak diubah.\n";
