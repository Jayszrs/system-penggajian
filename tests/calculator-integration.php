<?php
declare(strict_types=1);
// All writes are rolled back, and only the reserved browser test database is used.
$_ENV['DB_DATABASE']='penggajian_test';
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database;
use App\Services\PayrollCalculator;
use App\Domain\PayrollFields;
if(($_ENV['DB_DATABASE']??'')!=='penggajian_test')throw new RuntimeException('Refusing non-test database.');
$db=Database::connection();$db->beginTransaction();$checks=0;
try{
    $db->prepare('INSERT INTO bagian(kd_bagian,nama_bagian) VALUES(?,?)')->execute(['TCALC','Kalkulasi sementara']);
    $nip='000000000000009';
    $db->prepare('INSERT INTO karyawan(nip,nama,kd_bagian,gapok,t_jabatan,t_harian,t_lembur) VALUES(?,?,?,?,?,?,?)')->execute([$nip,'Fixture kalkulasi','TCALC','1000000','200000','20001','7500']);
    foreach(['tunj_konsumsi'=>'12000','tunj_infal'=>'12500','tunj_overtime'=>'8000'] as $t=>$v)$db->prepare("INSERT INTO `$t`(kd_bagian,jumlah) VALUES(?,?)")->execute(['TCALC',$v]);
    foreach(['p_bjbs'=>'50000','p_bank'=>'30000','p_lunak'=>'100000','p_anak'=>'25000','p_lain'=>'11111'] as $t=>$v)$db->prepare("INSERT INTO `$t`(nip,jumlah) VALUES(?,?)")->execute([$nip,$v]);
    $db->prepare('INSERT INTO tunj_jam(nip,jumlah,tarip) VALUES(?,?,?)')->execute([$nip,'3','25000']);
    $input=array_fill_keys(array_keys(PayrollFields::numeric()),'0');$input=[...$input,'nip'=>$nip,'hefektif'=>'21','hmasuk'=>'18','hijin'=>'2','halpha'=>'1','lembur'=>'3','infal'=>'2','overtime'=>'4','lain_lain'=>'8000','makan'=>'7000','cash_bon'=>'3000','tabungan'=>'1000','plain'=>'2000'];
    $actual=(new PayrollCalculator())->fromInput($input,true);
    $expected=['gapok'=>'1000000','t_jabatan'=>'200000','t_harian'=>'420021','t_konsumsi'=>'216000','ulembur'=>'22500','uinfal'=>'25000','uovertime'=>'32000','kelebihan'=>'75000','pinjaman_bsm'=>'50000','arisan'=>'30000','pinjaman_lunak'=>'100000','p_anak'=>'25000','lain1'=>'11111','absensi'=>'50003','tpenghasilan'=>'2009632','tpotongan'=>'268003','tbersih'=>'1741630'];
    foreach($expected as $f=>$v){if($actual[$f]!==$v)throw new RuntimeException("Unexpected master binding or amount: $f");$checks++;}
    // Demonstrate that the storage conversion is SQL rounding, not truncation.
    if(PayrollCalculator::round('50002.5')!=='50003')throw new RuntimeException('Half-rupiah storage conversion differs.');$checks++;
    echo "PASS: $checks master/rate/binding/rounding integration checks; test transaction rolled back.\n";
}finally{$db->rollBack();}
