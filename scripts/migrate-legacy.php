<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database;
use App\Domain\{MasterModules,PayrollFields};
use App\Services\PayrollRepository;

$file=$argv[1]??BASE_PATH.'/storage/legacy/export.json';
if(!is_file($file))throw new RuntimeException('Ekspor SQL Server belum tersedia. Jalankan scripts/restore-legacy.ps1 terlebih dahulu.');
$export=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if(($export['integrity']??'')!=='RESTORE VERIFYONLY and DBCC CHECKDB passed')throw new RuntimeException('Ekspor belum memiliki bukti pemeriksaan integritas.');
$db=Database::connection();$hash=$export['source_sha256'];
$q=$db->prepare("SELECT COUNT(*) FROM migration_runs WHERE source_sha256=? AND status='verified'");$q->execute([$hash]);
if($q->fetchColumn()){echo "Backup ini sudah dimigrasikan dan terverifikasi. Data operasional tidak ditimpa.\n";exit;}
$operational=array_fill_keys([...array_keys(MasterModules::all()),'penghasilan','tabungan'],true);
foreach(array_keys($operational) as $table){if((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn())throw new RuntimeException("Tabel $table sudah berisi data. Migrasi dibatalkan untuk mencegah penimpaan.");}
if((int)$db->query('SELECT COUNT(*) FROM legacy_tables')->fetchColumn())throw new RuntimeException('Arsip sudah tersedia. Gunakan database MySQL terpisah untuk migrasi berikutnya.');
function canonical(array $row): string {ksort($row);return json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
function source_value(mixed $value,array $column): mixed {
    if($value===null)return null;
    if(in_array($column['sql_type'],['char','nchar'],true))return rtrim((string)$value,' ');
    if(in_array($column['sql_type'],['numeric','decimal'],true))return bcadd((string)$value,'0',(int)$column['scale']);
    return $value;
}
function mysql_type(array $c): string {
    return match($c['sql_type']){
        'numeric','decimal'=>'DECIMAL('.(int)$c['precision'].','.(int)$c['scale'].')',
        'int','smallint','tinyint','bigint','bit'=>'BIGINT',
        'char','nchar','varchar','nvarchar'=>'VARCHAR('.max(1,min(16000,(int)$c['max_length']/(in_array($c['sql_type'],['nchar','nvarchar'],true)?2:1))).')',
        default=>'LONGTEXT'
    };
}
$issues=[];$tableSummaries=[];$operationalRows=0;$rawRows=0;$comparisons=0;
$employees=[];$departments=[];
foreach($export['tables'] as $t){if(strtolower($t['name'])==='karyawan')foreach($t['rows'] as $r)$employees[rtrim($r['nip']??'',' ')]=$r;if(strtolower($t['name'])==='bagian')foreach($t['rows'] as $r)$departments[rtrim($r['kd_bagian']??'',' ')]=$r;}
// Preserve every extra source column. Original identity columns use legacy_id;
// the application's id is a separate surrogate, never a replacement of source data.
foreach($export['tables'] as $table){$target=strtolower($table['name']);if(!isset($operational[$target]))continue;
    $available=$db->query("SHOW COLUMNS FROM `$target`")->fetchAll(PDO::FETCH_COLUMN);
    foreach($table['columns'] as $column){$name=strtolower($column['name']);$mapped=$name==='id'?'legacy_id':$name;if(!preg_match('/^[a-z0-9_]+$/',$mapped))throw new RuntimeException('Nama kolom sumber tidak didukung.');if(!in_array($mapped,$available,true))$db->exec("ALTER TABLE `$target` ADD COLUMN `$mapped` ".mysql_type($column).' NULL');}
}
$db->beginTransaction();
try {
    $archiveInsert=$db->prepare('INSERT INTO legacy_records(table_name,source_ordinal,payload,row_sha256) VALUES(?,?,?,?)');
    $catalogInsert=$db->prepare('INSERT INTO legacy_tables(table_name,row_count,schema_data,content_sha256) VALUES(?,?,?,?)');
    foreach($export['tables'] as $table){
        $name=$table['schema'].'.'.$table['name'];$target=strtolower($table['name']);$digests=[];$count=0;$mappedRows=[];
        foreach($table['rows'] as $index=>$row){
            $payload=canonical($row);$digest=hash('sha256',$payload);$archiveInsert->execute([$name,$index+1,$payload,$digest]);$digests[]=$digest;$rawRows++;
            if(isset($operational[$target])){
                $mapped=[];
                foreach($table['columns'] as $column){$field=strtolower($column['name']);$mapped[$field==='id'?'legacy_id':$field]=source_value($row[$column['name']],$column);}
                if($target==='penghasilan'){$employee=$employees[$mapped['nip']]??null;$dept=$employee?$departments[rtrim($employee['kd_bagian'],' ')]??null:null;$extra=['source'=>'legacy','employee_name'=>$employee?rtrim($employee['nama'],' '):null,'department_code'=>$employee?rtrim($employee['kd_bagian'],' '):null,'department_name'=>$dept?rtrim($dept['nama_bagian'],' '):null];}else $extra=[];
                $insert=[...$mapped,...$extra];$q=$db->prepare("INSERT INTO `$target` (`".implode('`,`',array_keys($insert)).'`) VALUES('.implode(',',array_fill(0,count($insert),'?')).')');$q->execute(array_values($insert));$id=(int)$db->lastInsertId();
                $q=$db->prepare("SELECT `".implode('`,`',array_keys($mapped))."` FROM `$target` WHERE id=?");$q->execute([$id]);$actual=$q->fetch();
                foreach($mapped as $key=>$value){$comparisons++;if(($value===null && $actual[$key]!==null)||($value!==null && (string)$actual[$key]!== (string)$value))throw new RuntimeException("Nilai operasional berbeda: $name baris ".($index+1)." kolom $key");}
                $operationalRows++;
            }
            $count++;
        }
        if($count!==(int)$table['row_count'])throw new RuntimeException("Jumlah baris tidak cocok: $name");
        $metadata=$table;unset($metadata['rows']);$tableHash=hash('sha256',implode("\n",$digests));$catalogInsert->execute([$name,$count,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$tableHash]);
        $q=$db->prepare('SELECT payload,row_sha256 FROM legacy_records WHERE table_name=? ORDER BY source_ordinal');$q->execute([$name]);$readDigests=[];foreach($q as $r){$rehash=hash('sha256',$r['payload']);if(!hash_equals($r['row_sha256'],$rehash))throw new RuntimeException("Arsip tidak cocok: $name");$readDigests[]=$rehash;}
        if(!hash_equals($tableHash,hash('sha256',implode("\n",$readDigests))))throw new RuntimeException("Checksum tabel tidak cocok: $name");
        $tableSummaries[]=['table'=>$name,'rows'=>$count,'columns'=>count($table['columns']),'sha256'=>$tableHash,'archive_verified'=>true,'operational'=>isset($operational[$target])];
        echo "$name: $count baris cocok\n";
    }
    $duplicates=$db->query('SELECT nip,CAST(bulan AS UNSIGNED) AS month,CAST(tahun AS UNSIGNED) AS year,COUNT(*) AS n FROM penghasilan GROUP BY nip,CAST(bulan AS UNSIGNED),CAST(tahun AS UNSIGNED) HAVING COUNT(*)>1')->fetchAll();
    if($duplicates)$issues[]=count($duplicates).' pasangan NIP/periode memiliki duplikasi sumber; seluruh baris tetap dipertahankan.';
    $orphans=(int)$db->query('SELECT COUNT(*) FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip WHERE k.nip IS NULL')->fetchColumn();
    if($orphans)$issues[]="$orphans transaksi sumber tidak memiliki master karyawan; identitas NIP dan nilai transaksi tetap dipertahankan.";
    $sumFields=['tpenghasilan','tpotongan','tbersih'];$totals=[];
    foreach($sumFields as $field)$totals[$field]=(string)$db->query("SELECT COALESCE(SUM(`$field`),0) FROM penghasilan")->fetchColumn();
    $summary=['tables'=>count($tableSummaries),'rows'=>$rawRows,'operational_rows'=>$operationalRows,'value_comparisons'=>$comparisons,'issues'=>$issues,'totals'=>$totals,'integrity'=>$export['integrity'],'table_details'=>$tableSummaries];
    $db->prepare('INSERT INTO migration_runs(source_sha256,source_database,status,summary) VALUES(?,?,?,?)')->execute([$hash,$export['source_database'],'verified',json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute(['legacy_definitions',json_encode($export['definitions'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute(['legacy_backup_metadata',json_encode(['header'=>$export['backup_header'],'files'=>$export['backup_files']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $db->commit();
    file_put_contents(BASE_PATH.'/storage/legacy/reconciliation.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    echo "Terverifikasi: $rawRows baris arsip, $operationalRows baris operasional, $comparisons perbandingan nilai.\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"Migrasi dibatalkan, transaksi di-rollback: ".$e->getMessage()."\n");exit(1);}
