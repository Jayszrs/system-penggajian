<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$_ENV['DB_DATABASE'] = 'penggajian_migration_test_' . bin2hex(random_bytes(4));
require dirname(__DIR__) . '/app/bootstrap.php';
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });

use App\Core\Database;
use App\Domain\{LegacyMapping, PayrollFields};
use App\Services\{LegacyMigration, LegacyMigrationBackup, LegacyMigrationTrace, PayrollCalculator};

$name = $_ENV['DB_DATABASE'];
$server = Database::connection(false);
$checks = 0;
function ensure(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException('FAIL: ' . $label); $checks++; }
function resetFixture(PDO $db): void {
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) $db->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
}
require __DIR__.'/legacy-migration-fixtures.php';
function hasCode(array $result, string $code): bool { return in_array($code, array_column($result['exceptions'], 'code'), true); }
try {
    ob_start(); require BASE_PATH . '/scripts/install.php'; ob_end_clean();
    $db = Database::connection();
    $migration = new LegacyMigration($db);
    $export = sourceFixture();
    $emptySnapshot = LegacyMigrationBackup::snapshot($db);
    $check = $migration->run($export, false);
    ensure($emptySnapshot === LegacyMigrationBackup::snapshot($db), 'Check mode never writes');
    ensure($check['status'] === 'needs_attention', 'Empty destination check cannot claim a complete import');
    $cliDirectory=BASE_PATH.'/storage/qa/migration/cli-'.bin2hex(random_bytes(4));mkdir($cliDirectory,0700,true);
    $cliExport=$cliDirectory.'/export.json';file_put_contents($cliExport,json_encode($export,JSON_THROW_ON_ERROR));
    $cli=proc_open([PHP_BINARY,BASE_PATH.'/scripts/migrate-legacy.php',$cliExport,'--check','--database='.$name,'--run-dir='.$cliDirectory],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,null,['bypass_shell'=>true]);
    fclose($pipes[0]);$cliOutput=stream_get_contents($pipes[1]);$cliError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    ensure(proc_close($cli)===2 && is_file($cliDirectory.'/reconciliation.json'),'CLI check reports incomplete with exit 2 and private report');
    ensure($emptySnapshot===LegacyMigrationBackup::snapshot($db),'CLI check leaves destination unchanged');
    $first = $migration->run($export);
    ensure($first['status'] === 'verified', 'Complete synthetic inventory imports');
    ensure($first['data_changes'] === 15, 'Every operational table imports its source row');
    ensure((int)$db->query('SELECT COUNT(*) FROM legacy_tables')->fetchColumn() === 19, 'All tables including empty metadata archived');
    foreach (LegacyMapping::tables() as $source => $map) {
        if (!$map['target']) continue;
        $tableName = $map['target'];
        $actual = $db->query("SELECT * FROM `$tableName`")->fetch();
        $sourceTable = array_values(array_filter($export['tables'], fn($t) => $t['name'] === $tableName))[0];
        foreach ($sourceTable['rows'][0] as $field => $value) ensure((string)$actual[$map['columns'][$field]] === rtrim((string)$value, ' '), 'All source columns preserved in ' . $tableName . '.' . $field);
    }
    ensure($db->query('SELECT nip FROM karyawan')->fetchColumn() === '00001', 'NIP zeroes preserved and CHAR padding trimmed');
    $originalPayroll = $db->query('SELECT * FROM penghasilan')->fetchAll();
    $baseline = LegacyMigrationBackup::snapshot($db);
    $again = $migration->run($export);
    ensure($again['data_changes'] === 0 && !$again['metadata_changed'], 'Repeat produces zero changes');
    ensure($baseline === LegacyMigrationBackup::snapshot($db), 'Repeat has zero persistent writes');
    $shuffled = $export; $shuffled['tables'] = array_reverse($shuffled['tables']);
    foreach ($shuffled['tables'] as &$table) $table['rows'] = array_reverse($table['rows']); unset($table);
    ensure(!$migration->run($shuffled)['metadata_changed'], 'Table and row order do not affect reconciliation identity');
    $values = array_fill_keys(array_keys(PayrollFields::numeric()), '0');
    $values += ['nip' => '00001', 'bulan' => '10', 'tahun' => '2026'];
    $values['hefektif'] = $values['hmasuk'] = '21';
    $calculated = (new PayrollCalculator())->fromInput($values, true);
    ensure($calculated['p_anak'] === '3000' && $calculated['pinjaman_lunak'] === '3000' && $calculated['pinjaman_bsm'] === '3000' && $calculated['arisan'] === '3000' && $calculated['plain'] === '3000', 'Imported deduction masters feed correct runtime fields');
    ensure($calculated['t_konsumsi'] === '63000' && $calculated['kelebihan'] === '7500000', 'Department and teaching masters feed runtime');
    $backupPath = BASE_PATH . '/storage/qa/migration/backup-' . bin2hex(random_bytes(4));
    $backup = LegacyMigrationBackup::create($db, $backupPath);
    ensure($backup['restoration_verified'] && $baseline === LegacyMigrationBackup::snapshot($db), 'Full MySQL backup restores and leaves source intact');
    ensure(!glob($backupPath . '/.mysql-*.cnf'), 'Temporary credentials removed');

    resetFixture($db); $migration->run($export);
    $originalPayroll = $db->query('SELECT * FROM penghasilan')->fetchAll();
    $db->exec("DELETE FROM app_settings WHERE setting_key LIKE 'legacy_migration_state:%'");
    $db->exec('DELETE FROM p_lunak');
    $repair = $migration->run($export);
    ensure($repair['data_changes'] === 1 && $repair['status'] === 'verified', 'Missing master after old import repaired automatically');
    ensure(!$migration->run($export)['metadata_changed'], 'Repair immediately idempotent');
    ensure($db->query('SELECT * FROM penghasilan')->fetchAll() === $originalPayroll, 'Master repair preserves all payroll columns and versions');
    $db->exec("UPDATE p_anak SET jumlah=0");
    $conflict = $migration->run($export);
    ensure($conflict['status'] === 'needs_attention' && hasCode($conflict, 'preserved_local_difference'), 'Existing local zero preserved as conflict');
    ensure($db->query('SELECT jumlah FROM p_anak')->fetchColumn() === '0', 'Local zero not replaced');
    $db->exec('UPDATE p_bjbs SET jumlah=NULL');
    ensure(hasCode($migration->run($export), 'preserved_local_difference') && $db->query('SELECT jumlah FROM p_bjbs')->fetchColumn() === null, 'Existing NULL not replaced');
    $db->exec("INSERT INTO bagian(kd_bagian,nama_bagian) VALUES('LOCAL','Bagian Lokal')");
    $migration->run($export);
    ensure((int)$db->query("SELECT COUNT(*) FROM bagian WHERE kd_bagian='LOCAL'")->fetchColumn() === 1, 'Local-only rows preserved');
    $db->exec('DELETE FROM p_bank');
    ensure(hasCode($migration->run($export), 'preserved_deletion') && (int)$db->query('SELECT COUNT(*) FROM p_bank')->fetchColumn() === 0, 'Ledger deletion preserved');

    resetFixture($db); $migration->run($export);
    $db->exec("DELETE FROM app_settings WHERE setting_key LIKE 'legacy_migration_state:%'");
    $deleted = $db->query('SELECT * FROM p_lunak')->fetch();
    $db->prepare("INSERT INTO audit_log(action,entity,record_key,before_data) VALUES('delete','p_lunak',?,?)")->execute([$deleted['id'], json_encode($deleted)]);
    $db->exec('DELETE FROM p_lunak');
    ensure(hasCode($migration->run($export), 'preserved_deletion') && (int)$db->query('SELECT COUNT(*) FROM p_lunak')->fetchColumn() === 0, 'Pre-ledger audited deletion preserved');

    resetFixture($db); $sparse = $export;
    foreach (['p_lain','tabungan','tunj_harian','tunj_lembur','tunj_jam'] as $empty) changeTable($sparse,$empty,function(&$t){$t['rows']=[];});
    ensure($migration->run($sparse)['status'] === 'verified', 'Source-empty masters are valid');
    ensure((int)$db->query('SELECT COUNT(*) FROM p_lain')->fetchColumn() === 0 && $db->query('SELECT plain FROM penghasilan')->fetchColumn() === '1', 'Historical deduction never synthesizes a master');

    resetFixture($db); $duplicate = $export;
    changeTable($duplicate,'p_anak',function(&$t){$r=$t['rows'][0];$r['id']='8';$t['rows'][]=$r;});
    $bad = $migration->run($duplicate);
    ensure($bad['status'] === 'needs_attention' && hasCode($bad,'source_key_duplicate') && (int)$db->query('SELECT COUNT(*) FROM p_anak')->fetchColumn() === 0, 'Duplicate natural keys archived without arbitrary choice');
    ensure((int)$db->query("SELECT COUNT(*) FROM legacy_records WHERE table_name='dbo.p_anak'")->fetchColumn() === 2, 'Duplicate source multiplicity retained');

    resetFixture($db); $orphan = $export;
    changeTable($orphan,'p_bank',function(&$t){$t['rows'][0]['nip']=str_pad('MISSING',15);});
    $bad=$migration->run($orphan);
    ensure($bad['status']==='needs_attention' && (hasCode($bad,'orphan_reference') || hasCode($bad,'source_orphan_reference')) && (int)$db->query('SELECT COUNT(*) FROM p_bank')->fetchColumn()===0, 'Orphan component retained only in archive with issue');

    resetFixture($db); $negative = $export;
    changeTable($negative,'p_lunak',function(&$t){$t['rows'][0]['jumlah']='-999999999999999999';});
    changeTable($negative,'karyawan',function(&$t){$t['rows'][0]['t_jabatan']=null;$t['rows'][0]['t_lembur']='0';});
    ensure($migration->run($negative)['status']==='verified', 'Negative and 18-digit source amounts accepted exactly');
    ensure($db->query('SELECT jumlah FROM p_lunak')->fetchColumn()==='-999999999999999999' && $db->query('SELECT t_jabatan FROM karyawan')->fetchColumn()===null, 'Large negative and NULL values preserved');

    resetFixture($db); $extraSchema = $export;
    $extraTable=$extraSchema['tables'][0];$extraTable['schema']='other';$extraSchema['tables'][]=$extraTable;
    ensure(hasCode($migration->run($extraSchema),'unknown_table'), 'Same table in another schema not merged automatically');

    resetFixture($db); $incomplete=$export;
    $incomplete['tables']=array_values(array_filter($incomplete['tables'],fn($t)=>$t['name']!=='p_anak'));
    ensure($migration->run($incomplete)['status']==='needs_attention','Entire absent operational table cannot silently pass as complete');
    ensure(!(new LegacyMigrationTrace($db))->overview()['complete'],'Trace cannot claim completeness for an omitted master table');

    resetFixture($db);$unproven=$export;$unproven['format_version']=2;
    try{$migration->run($unproven);ensure(false,'V2 provenance required');}catch(RuntimeException){ensure(true,'V2 export without restore provenance rejected');}
    ensure((int)$db->query('SELECT COUNT(*) FROM legacy_records')->fetchColumn()===0,'Rejected provenance writes no archive');

    resetFixture($db); $unknownColumn = $export;
    changeTable($unknownColumn,'p_anak',function(&$t){$t['columns'][]=['name'=>'extra_deduction','sql_type'=>'numeric','precision'=>18,'scale'=>0,'max_length'=>9];$t['rows'][0]['extra_deduction']='123456';});
    ensure(hasCode($migration->run($unknownColumn),'unknown_column'), 'Unknown business column explicitly reported');
    $raw=json_decode($db->query("SELECT payload FROM legacy_records WHERE table_name='dbo.p_anak'")->fetchColumn(),true);
    ensure($raw['extra_deduction']==='123456','Unknown source values remain lossless in archive');

    resetFixture($db); $fraction = $export;
    changeTable($fraction,'p_lunak',function(&$t){foreach($t['columns'] as &$c)if($c['name']==='jumlah')$c['scale']=2;unset($c);$t['rows'][0]['jumlah']='1234.25';});
    $bad=$migration->run($fraction);
    ensure($bad['status']==='needs_attention' && (int)$db->query('SELECT COUNT(*) FROM p_lunak')->fetchColumn()===0,'Unrepresentable decimal is never rounded into an operational master');
    $raw=json_decode($db->query("SELECT payload FROM legacy_records WHERE table_name='dbo.p_lunak'")->fetchColumn(),true);
    ensure($raw['jumlah']==='1234.25','Fractional source amount retained in archive');

    resetFixture($db); $mixed = $export;
    foreach($mixed['tables'] as &$table){$table['schema']=strtoupper($table['schema']);$table['name']=strtoupper($table['name']);foreach($table['columns'] as &$column)$column['name']=strtoupper($column['name']);unset($column);foreach($table['rows'] as &$row)$row=array_change_key_case($row,CASE_UPPER);unset($row);}unset($table);
    ensure($migration->run($mixed)['status']==='verified','Mixed-case schema, table, and field names use explicit canonical mappings');

    resetFixture($db); $migration->run($export);
    $columns=$db->query('SHOW COLUMNS FROM penghasilan')->fetchAll(PDO::FETCH_COLUMN);$columns=array_values(array_diff($columns,['id','created_at','updated_at']));
    $quoted=implode('`,`',$columns);$db->exec("INSERT INTO penghasilan (`$quoted`) SELECT `$quoted` FROM penghasilan LIMIT 1");
    ensure(hasCode($migration->run($export),'target_identity_conflict'),'Multiple target payroll matches never selected arbitrarily');

    resetFixture($db); $duplicate = $export;
    changeTable($duplicate,'p_anak',function(&$t){$r=$t['rows'][0];$r['id']='8';$t['rows'][]=$r;});
    $migration->run($duplicate);$baseline=LegacyMigrationBackup::snapshot($db);
    changeTable($duplicate,'p_anak',function(&$t){$t['rows']=array_reverse($t['rows']);});
    ensure(!$migration->run($duplicate)['metadata_changed'] && $baseline===LegacyMigrationBackup::snapshot($db),'Reordering exceptional source rows does not duplicate ledger or runs');

    resetFixture($db);$duplicateId=$export;
    changeTable($duplicateId,'p_anak',function(&$t){$r=$t['rows'][0];$r['jumlah']='999';$t['rows'][]=$r;});
    $migration->run($duplicateId);$baseline=LegacyMigrationBackup::snapshot($db);
    changeTable($duplicateId,'p_anak',function(&$t){$t['rows']=array_reverse($t['rows']);});
    ensure(!$migration->run($duplicateId)['metadata_changed'] && $baseline===LegacyMigrationBackup::snapshot($db),'Duplicate source identities retain stable row multiplicity across reordered exports');

    resetFixture($db); $db->exec("CREATE TRIGGER qa_reject_component BEFORE INSERT ON p_lunak FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic migration failure'");
    try { $migration->run($export); ensure(false, 'Injected failure must throw'); } catch (PDOException) { ensure(true, 'Injected failure throws'); }
    ensure((int)$db->query('SELECT COUNT(*) FROM karyawan')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM legacy_records')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM migration_runs')->fetchColumn()===0, 'Failure rolls back archive, earlier inserts, and metadata');
    $db->exec('DROP TRIGGER qa_reject_component');

    resetFixture($db); $migration->run($export);$baseline=LegacyMigrationBackup::snapshot($db);
    $wrong=$export;$wrong['source_sha256']=str_repeat('b',64);
    try { $migration->run($wrong);ensure(false,'Different backup must reject'); } catch(RuntimeException){ensure(true,'Different backup rejected');}
    ensure($baseline===LegacyMigrationBackup::snapshot($db),'Rejected backup writes nothing');
    $db->exec("UPDATE legacy_records SET payload='{}' WHERE id=(SELECT id FROM (SELECT MIN(id) AS id FROM legacy_records) q)");
    try { $migration->run($export);ensure(false,'Corrupted archive must reject'); } catch(RuntimeException){ensure(true,'Corrupted archive rejected');}
    echo "PASS: $checks checks; isolated synthetic migration, repair, provenance, backups, runtime master mappings, rollback and idempotence.\n";
} finally {
    if ($db??null) { if ($db->inTransaction()) $db->rollBack(); }
    if (preg_match('/^penggajian_migration_test_[a-f0-9]{8}$/D',$name)) $server->exec("DROP DATABASE IF EXISTS `$name`");
}
