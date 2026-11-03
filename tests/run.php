<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\{PayrollCalculator,PayrollRepository,LegacyMigration};
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1); });
use App\Domain\PayrollFields;
use App\Core\Database;
$checks=0;
function check(bool $condition,string $label): void {global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;}
$calculator=new PayrollCalculator();
check(count(PayrollFields::columns())===32,'32 legacy fields covered');
check(count(array_unique(PayrollFields::columns()))===32,'No duplicate field mappings');
check(money('999999999999999999')==='Rp 999.999.999.999.999.999','18 digit money without float precision loss');
foreach(['0.5'=>'1','1.5'=>'2','2.49'=>'2','-0.5'=>'-1','-1.5'=>'-2','0'=>'0'] as $value=>$expected)check(PayrollCalculator::round((string)$value)===$expected,'SQL numeric rounding '.$value);
$base=array_fill_keys(array_keys(PayrollFields::numeric()),'0');$base['hefektif']='21';$base['hmasuk']='21';$base['gapok']='1000000';$base['cash_bon']='250000';$base['plain']='50000';$base['lain1']='100000';
$r=$calculator->fromInput($base);check($r['tpenghasilan']==='1100000'&&$r['tpotongan']==='300000'&&$r['tbersih']==='800000','Gross deductions and net together');
$invalid=$base;$invalid['hmasuk']='22';try{$calculator->fromInput($invalid);check(false,'Invalid attendance rejected');}catch(InvalidArgumentException){check(true,'Invalid attendance rejected');}
$invalid=$base;$invalid['gapok']='1e10';try{$calculator->fromInput($invalid);check(false,'Scientific notation rejected');}catch(InvalidArgumentException){check(true,'Scientific notation rejected');}
$file=$argv[1]??BASE_PATH.'/storage/legacy/export.json';
if(!is_file($file)){
    $files=glob(BASE_PATH.'/storage/legacy/*/*/export.json')?:[];
    usort($files,static fn($a,$b)=>filemtime($b)<=>filemtime($a));
    $file=$files[0]??'';
}
if(!is_file($file))throw new RuntimeException('Ekspor lokal belum tersedia. Jalankan migrasi otomatis atau berikan path ekspor sebagai argumen.');
$export=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
$source=[];foreach($export['tables'] as $t)$source[strtolower($t['schema'].'.'.$t['name'])]=$t;
check(isset($source['dbo.penghasilan']),'Payroll source table available');
$absenceChecks=0;$payrollRows=$source['dbo.penghasilan']['rows'];
foreach($payrollRows as $index=>$row){
    $computed=$calculator->totals($row);
    foreach(PayrollFields::TOTALS as $field=>$label)check($computed[$field]===(string)$row[$field],"Stored payroll row $index $field");
    $calculator->validateAttendance($row);
    if(bccomp((string)$row['hefektif'],'0',0)>0){$daily=bcdiv((string)$row['t_harian'],(string)$row['hefektif'],4);$weighted=bcadd((string)$row['hijin'],bcmul((string)$row['halpha'],'0.5',4),4);check(bccomp(bcmul($daily,$weighted,4),(string)$row['absensi'],4)===0,"Stored absence formula row $index");$absenceChecks++;}
}
$db=Database::connection();
$summary=(new LegacyMigration($db))->run($export,false);
check($summary['status']==='verified','All operational source fields and master relationships match');
check($summary['tables']===count($source),'Every source table classified');
check($summary['rows']===array_sum(array_column($export['tables'],'row_count')),'All source rows archived');
$ids=[];foreach($summary['mapped_rows'] as $row)if($row['target_table']==='penghasilan' && $row['target_id']!==null)$ids[]=$row['target_id'];
check(count($ids)===count($payrollRows),'Every source payroll transaction matched exactly once');
foreach(array_keys(PayrollFields::TOTALS) as $field){
    $expected='0';foreach($payrollRows as $row)$expected=bcadd($expected,(string)($row[$field]??'0'),0);
    $actual='0';if($ids){$q=$db->prepare("SELECT SUM(`$field`) FROM penghasilan WHERE id IN(".implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$actual=(string)$q->fetchColumn();}
    check($actual===$expected,'Matched source aggregate '.$field.' excludes local-only additions');
}
$last=(new PayrollRepository())->period();
$latest=$db->query("SELECT CAST(bulan AS UNSIGNED) AS month,CAST(tahun AS UNSIGNED) AS year FROM penghasilan WHERE bulan REGEXP '^[0-9]+$' AND tahun REGEXP '^[0-9]+$' ORDER BY CAST(tahun AS UNSIGNED) DESC,CAST(bulan AS UNSIGNED) DESC LIMIT 1")->fetch();
if($latest)check($last['bulan']===(int)$latest['month']&&$last['tahun']===(int)$latest['year'],'Default period follows actual latest transaction');
$archiveChecks=0;
foreach($db->query('SELECT payload,row_sha256 FROM legacy_records') as $row){check(hash_equals($row['row_sha256'],hash('sha256',$row['payload'])),'Archived row checksum');$archiveChecks++;}
echo "PASS: $checks checks; ".count($payrollRows)." source transactions, $absenceChecks attendance formulas, $archiveChecks archive checksums, all master fields reconciled.\n";
