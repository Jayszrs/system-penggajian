<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database=$argv[1]??'';
if (!preg_match('/^penggajian_print_test_[a-f0-9]{8}$/', $database)) { fwrite(STDERR,"Nama database uji tidak valid.\n"); exit(1); }
$_ENV['DB_DATABASE']=$database;
require dirname(__DIR__).'/app/bootstrap.php';
try {
    $server=App\Core\Database::connection(false);
    if (($argv[2]??'')==='cleanup') {
        $users=$server->query("SELECT username FROM `$database`.app_users")->fetchAll(PDO::FETCH_COLUMN);
        if ($users!==['qa_print']) throw new RuntimeException('Identitas database uji tidak sesuai.');
        $server->exec("DROP DATABASE `$database`");
        exit;
    }
    $q=$server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');$q->execute([$database]);
    if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Database sudah ada; tidak ditimpa.');
    ob_start();require BASE_PATH.'/scripts/install.php';ob_end_clean();
    $db=App\Core\Database::connection();
    $db->prepare('INSERT INTO app_users(username,name,password_hash) VALUES(?,?,?)')->execute(['qa_print','Accounting QA Cetak',password_hash('Qa!Print-2026-Local',PASSWORD_DEFAULT)]);
    $db->prepare('INSERT INTO legacy_tables(table_name,row_count,schema_data,content_sha256) VALUES(?,?,?,?)')->execute(['qa_print_archive',0,'[]',hash('sha256','')]);
    $db->exec("INSERT INTO bagian(kd_bagian,nama_bagian) VALUES('PA001','Bagian Cetak Satu'),('PB001','Bagian Cetak Dua')");
    $longName='Karyawan Pengujian Nama Sangat Panjang untuk Verifikasi Tata Letak Slip dan Tanda Tangan Tanpa Pemotongan serta Kelengkapan Identitas Penerima Gaji';
    $employee=$db->prepare('INSERT INTO karyawan(nip,nama,kd_bagian) VALUES(?,?,?)');
    $employee->execute(['000000000000001',$longName,'PA001']);
    $employee->execute(['000000000000002','Karyawan Pengujian Kedua','PB001']);
    $employee->execute(['000000000000003','Karyawan Contoh','PA001']);
    $insert=function(string $nip,string $name,string $department,int $month,int $year,array $values) use($db): array {
        $numbers=array_fill_keys(array_keys(App\Domain\PayrollFields::numeric()),'0');
        $numbers=array_replace($numbers,$values);
        $numbers['tpenghasilan']=App\Services\PayrollRepository::moneySum(array_intersect_key($numbers,App\Domain\PayrollFields::INCOME));
        $numbers['tpotongan']=App\Services\PayrollRepository::moneySum(array_intersect_key($numbers,App\Domain\PayrollFields::DEDUCTIONS));
        $numbers['tbersih']=bcsub($numbers['tpenghasilan'],$numbers['tpotongan'],0);
        $record=['nip'=>$nip,'bulan'=>str_pad((string)$month,2,'0',STR_PAD_LEFT),'tahun'=>(string)$year,...$numbers,'source'=>'manual','employee_name'=>$name,'department_code'=>$department,'department_name'=>$department==='PA001'?'Bagian Cetak Satu':'Bagian Cetak Dua'];
        $columns=implode('`,`',array_keys($record));$parameters=implode(',',array_fill(0,count($record),'?'));
        $db->prepare("INSERT INTO penghasilan (`$columns`) VALUES($parameters)")->execute(array_values($record));
        return ['id'=>(int)$db->lastInsertId(),'name'=>$name,'nip'=>$nip,'numbers'=>$numbers];
    };
    $first=$insert('000000000000001',$longName,'PA001',10,2026,['gapok'=>'900000000000000000','t_jabatan'=>'250000','lain1'=>'23456','kelebihan'=>'7890','cash_bon'=>'-1000','p_anak'=>'5000','hefektif'=>'21','hmasuk'=>'20','lembur'=>'2','infal'=>'3','overtime'=>'4']);
    $second=$insert('000000000000002','Karyawan Pengujian Kedua','PB001',10,2026,['cash_bon'=>'1000','hefektif'=>'21','hmasuk'=>'0']);
    $reference=$insert('000000000000003','Karyawan Contoh','PA001',9,2026,['gapok'=>'1500000','t_jabatan'=>'400000','t_harian'=>'1260000','t_konsumsi'=>'525000','ulembur'=>'30000','p_anak'=>'100000','pinjaman_lunak'=>'75000','pinjaman_bsm'=>'50000','hefektif'=>'21','hmasuk'=>'21','lembur'=>'2']);
    $insert('000000000000001',$longName,'PA001',12,2025,['gapok'=>'100000','hefektif'=>'20','hmasuk'=>'20']);
    for($i=0;$i<96;$i++)$insert('000000000000001',$longName,'PA001',$i%12+1,2010+intdiv($i,12),['gapok'=>'100000','hefektif'=>'20','hmasuk'=>'20']);
    $monthly=$db->query("SELECT SUM(tpenghasilan) AS tpenghasilan,SUM(tpotongan) AS tpotongan,SUM(tbersih) AS tbersih FROM penghasilan WHERE tahun='2026' AND bulan='10'")->fetch();
    $range=$db->query('SELECT SUM(tpenghasilan) AS tpenghasilan,SUM(tpotongan) AS tpotongan,SUM(tbersih) AS tbersih FROM penghasilan')->fetch();
    echo json_encode(['first'=>$first,'second'=>$second,'reference'=>$reference,'officer'=>'Accounting QA Cetak','monthly_totals'=>$monthly,'range_totals'=>$range],JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);
}
