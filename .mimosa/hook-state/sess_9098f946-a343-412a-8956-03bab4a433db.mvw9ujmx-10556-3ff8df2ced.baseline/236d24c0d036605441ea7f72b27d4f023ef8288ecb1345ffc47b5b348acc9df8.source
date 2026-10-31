<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\{PayrollCalculator,PayrollRepository};
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
$export=json_decode(file_get_contents(BASE_PATH.'/storage/legacy/export.json'),true,512,JSON_THROW_ON_ERROR);
check(count($export['tables'])===19,'All 19 source tables exported');$source=[];foreach($export['tables'] as $t)$source[strtolower($t['name'])]=$t;
check($source['penghasilan']['row_count']===332,'332 restored active payroll records');
$absenceChecks=0;
foreach($source['penghasilan']['rows'] as $index=>$row){
    $computed=$calculator->totals($row);
    foreach(PayrollFields::TOTALS as $field=>$label)check($computed[$field]===(string)$row[$field],"Stored payroll row $index $field");
    $calculator->validateAttendance($row);
    if(bccomp((string)$row['hefektif'],'0',0)>0){$daily=bcdiv((string)$row['t_harian'],(string)$row['hefektif'],4);$weighted=bcadd((string)$row['hijin'],bcmul((string)$row['halpha'],'0.5',4),4);check(bccomp(bcmul($daily,$weighted,4),(string)$row['absensi'],4)===0,"Stored absence formula row $index");$absenceChecks++;}
}
$db=Database::connection();$run=$db->query("SELECT summary FROM migration_runs WHERE status='verified' ORDER BY id DESC LIMIT 1")->fetchColumn();check($run!==false,'Verified migration exists');$summary=json_decode($run,true);
check($summary['tables']===19 && $summary['rows']===485 && $summary['operational_rows']===396,'Migration counts match exact source');
check((int)$db->query('SELECT COUNT(*) FROM legacy_records')->fetchColumn()===485,'No archive rows missing');
check((int)$db->query('SELECT COUNT(*) FROM legacy_tables')->fetchColumn()===19,'Empty tables retained in archive');
foreach(array_keys(PayrollFields::TOTALS) as $field){$expected='0';foreach($source['penghasilan']['rows'] as $row)$expected=bcadd($expected,(string)($row[$field]??'0'),0);check((string)$db->query("SELECT SUM(`$field`) FROM penghasilan")->fetchColumn()===$expected,'Migrated aggregate '.$field);}
$last=(new PayrollRepository())->period();check($last['bulan']===9&&$last['tahun']===2025,'Leading zero month selects September 2025');
$q=$db->query("SELECT payload,row_sha256 FROM legacy_records ORDER BY id");foreach($q as $row)check(hash_equals($row['row_sha256'],hash('sha256',$row['payload'])),'Archived row checksum');
echo "PASS: $checks checks; 332 stored payroll fixtures, $absenceChecks attendance formulas, 485 archive checksums.\n";
