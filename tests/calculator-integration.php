<?php
declare(strict_types=1);
// All writes are rolled back, and only the reserved browser test database is used.
$_ENV['DB_DATABASE']='penggajian_test';
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database;
use App\Services\PayrollCalculator;
use App\Domain\{PayrollFields,PayrollValidationException};
if(($_ENV['DB_DATABASE']??'')!=='penggajian_test')throw new RuntimeException('Refusing non-test database.');
$db=Database::connection();$db->beginTransaction();$checks=0;
try{
    $db->prepare('INSERT INTO bagian(kd_bagian,nama_bagian) VALUES(?,?)')->execute(['TCALC','Kalkulasi sementara']);
    $nip='000000000000009';
    $db->prepare('INSERT INTO karyawan(nip,nama,kd_bagian,gapok,t_jabatan,t_harian,t_lembur) VALUES(?,?,?,?,?,?,?)')->execute([$nip,'Fixture kalkulasi','TCALC','1000000','200000','20001','7500']);
    foreach(['tunj_konsumsi'=>'12000','tunj_infal'=>'12500','tunj_overtime'=>'8000'] as $t=>$v)$db->prepare("INSERT INTO `$t`(kd_bagian,jumlah) VALUES(?,?)")->execute(['TCALC',$v]);
    foreach(['p_bjbs'=>'50000','p_bank'=>'30000','p_lunak'=>'100000','p_anak'=>'25000','p_lain'=>'11111'] as $t=>$v)$db->prepare("INSERT INTO `$t`(nip,jumlah) VALUES(?,?)")->execute([$nip,$v]);
    $db->prepare('INSERT INTO tunj_jam(nip,jumlah,tarip) VALUES(?,?,?)')->execute([$nip,'3','25000']);
    $input=array_fill_keys(array_keys(PayrollFields::numeric()),'0');$input=[...$input,'nip'=>$nip,'bulan'=>'2','tahun'=>'2024','hefektif'=>'21','hmasuk'=>'18','hijin'=>'2','halpha'=>'1','lembur'=>'3','infal'=>'2','overtime'=>'4','lain_lain'=>'8000','lain1'=>'4444','makan'=>'7000','cash_bon'=>'3000','tabungan'=>'1000','plain'=>'2000'];
    $calculator=new PayrollCalculator();$evaluation=$calculator->evaluate($input,true);$actual=$evaluation['values'];
    $expected=['gapok'=>'1000000','t_jabatan'=>'200000','t_harian'=>'420021','t_konsumsi'=>'216000','ulembur'=>'22500','uinfal'=>'25000','uovertime'=>'32000','kelebihan'=>'75000','pinjaman_bsm'=>'50000','arisan'=>'30000','pinjaman_lunak'=>'100000','p_anak'=>'25000','plain'=>'11111','lain1'=>'4444','absensi'=>'50003','tpenghasilan'=>'2002965','tpotongan'=>'277114','tbersih'=>'1725851'];
    foreach($expected as $f=>$v){if($actual[$f]!==$v)throw new RuntimeException("Unexpected master binding or amount: $f");$checks++;}
    // Demonstrate that the storage conversion is SQL rounding, not truncation.
    if(PayrollCalculator::round('50002.5')!=='50003')throw new RuntimeException('Half-rupiah storage conversion differs.');$checks++;
    if(bcsub($actual['tpenghasilan'],$actual['tpotongan'],0)!==$actual['tbersih'])throw new RuntimeException('Accounting equation is unbalanced.');$checks++;
    if($evaluation!==$calculator->evaluate($input,true))throw new RuntimeException('Preview snapshots must be deterministic.');$checks++;
    if($calculator->fromInput([...$input,...$actual],false)['tbersih']!==$actual['tbersih'])throw new RuntimeException('Saving unchanged rounded values changes net.');$checks++;
    $before=[...$input,...$actual,'calculation_version'=>'legacy_v1','calculation_snapshot'=>null];
    if(!in_array('historical_master_recalculation',array_column($calculator->evaluate($input,true,['before'=>$before])['issues'],'code'),true))throw new RuntimeException('Historical master recalculation must warn.');$checks++;
    $db->prepare('DELETE FROM tunj_konsumsi WHERE kd_bagian=?')->execute(['TCALC']);
    try{$calculator->evaluate($input,true);throw new RuntimeException('Missing consumption rate was silently treated as zero.');}catch(PayrollValidationException $e){if($e->issues[0]['code']!=='missing_required_rate'||$e->issues[0]['field']!=='t_konsumsi')throw $e;$checks++;}
    $db->prepare('INSERT INTO tunj_konsumsi(kd_bagian,jumlah) VALUES(?,?)')->execute(['TCALC','0']);
    if($calculator->evaluate($input,true)['values']['t_konsumsi']!=='0')throw new RuntimeException('Explicit zero rate must be valid.');$checks++;
    $db->prepare('UPDATE tunj_konsumsi SET jumlah=NULL WHERE kd_bagian=?')->execute(['TCALC']);
    try{$calculator->evaluate($input,true);throw new RuntimeException('NULL consumption rate was silently treated as zero.');}catch(PayrollValidationException $e){if($e->issues[0]['code']!=='missing_required_rate')throw $e;$checks++;}
    if($calculator->evaluate([...$input,'hmasuk'=>'0'],true)['values']['t_konsumsi']!=='0')throw new RuntimeException('Unused NULL rate must not block zero activity.');$checks++;
    $db->prepare('UPDATE tunj_konsumsi SET jumlah=0 WHERE kd_bagian=?')->execute(['TCALC']);
    $db->prepare('DELETE FROM tunj_infal WHERE kd_bagian=?')->execute(['TCALC']);
    if($calculator->evaluate([...$input,'infal'=>'0'],true)['values']['uinfal']!=='0')throw new RuntimeException('Unused missing rate must not create an amount.');$checks++;
    $db->prepare('DELETE FROM p_lain WHERE nip=?')->execute([$nip]);
    $withoutOptional=$calculator->evaluate([...$input,'infal'=>'0'],true);
    if($withoutOptional['values']['plain']!=='0'||$withoutOptional['values']['lain1']!=='4444')throw new RuntimeException('Missing optional deduction must be zero without changing manual income.');$checks++;
    echo "PASS: $checks master/rate/binding/rounding integration checks; test transaction rolled back.\n";
}finally{$db->rollBack();}
