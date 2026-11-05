<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$database=$argv[1]??'';
if(!preg_match('/^penggajian_migration_browser_[a-f0-9]{8}$/D',$database)){fwrite(STDERR,"Nama database uji tidak valid.\n");exit(1);}
$_ENV['DB_DATABASE']=$database;
require dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/legacy-migration-fixtures.php';
use App\Core\Database;
use App\Services\{LegacyMigration,LegacyMigrationBackup};
$created=false;
try{
    $server=Database::connection(false);$mode=$argv[2]??'setup';
    if(in_array($mode,['snapshot','cleanup'],true)){
        $db=Database::connection();
        $users=$db->query('SELECT username FROM app_users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        if($users!==['qa_migration'])throw new RuntimeException('Identitas database uji tidak sesuai.');
        if($db->query("SELECT setting_value FROM app_settings WHERE setting_key='qa_migration_browser'")->fetchColumn()!=='synthetic')throw new RuntimeException('Penanda database uji tidak sesuai.');
        if($mode==='cleanup'){$server->exec("DROP DATABASE `$database`");exit;}
        echo json_encode(LegacyMigrationBackup::snapshot($db),JSON_THROW_ON_ERROR);exit;
    }
    if($mode!=='setup')throw new RuntimeException('Mode fixture tidak dikenal.');
    $q=$server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');$q->execute([$database]);
    if((int)$q->fetchColumn())throw new RuntimeException('Database uji sudah tersedia; tidak ditimpa.');
    $created=true;ob_start();require BASE_PATH.'/scripts/install.php';ob_end_clean();$db=Database::connection();
    $export=sourceFixture();
    foreach(['tunj_harian','tunj_lembur','tabungan'] as $name)changeTable($export,$name,static function(&$t){$t['rows']=[];});
    changeTable($export,'karyawan',static function(&$t){
        $t['rows'][0]['nama']=str_pad('Karyawan <Uji> & Aman',30);
        $second=$t['rows'][0];$second['nip']=str_pad('00002',15);$second['id']='8';$second['nama']=str_pad('Karyawan Uji Kedua',30);$second['kd_bagian']='Q0002';$t['rows'][]=$second;
    });
    changeTable($export,'bagian',static function(&$t){$second=$t['rows'][0];$second['kd_bagian']='Q0002';$second['nama_bagian']=str_pad('Bagian Uji Kedua',20);$t['rows'][]=$second;});
    changeTable($export,'penghasilan',static function(&$t){$second=$t['rows'][0];$second['nip']=str_pad('00002',15);$second['bulan']='12';$second['tahun']='2024';$second['cash_bon']='-1';$second['lain1']=null;$t['rows'][]=$second;});
    changeTable($export,'p_lunak',static function(&$t){$t['rows'][0]['jumlah']='-987654321';});
    changeTable($export,'p_bjbs',static function(&$t){$t['rows'][0]['jumlah']=null;});
    $migration=new LegacyMigration($db);$first=$migration->run($export);
    if($first['status']!=='verified')throw new RuntimeException('Fixture awal tidak lolos pemeriksaan.');
    $db->exec('UPDATE p_anak SET jumlah=0');$db->exec('DELETE FROM p_bank');
    $second=$migration->run($export);
    if($second['status']!=='needs_attention')throw new RuntimeException('Fixture perbedaan lokal belum tercatat.');
    $db->prepare('INSERT INTO karyawan(nip,nama,kd_bagian,gapok,t_jabatan,t_harian,t_lembur) VALUES(?,?,?,?,?,?,?)')->execute(['LOCAL001','Karyawan Lokal dengan Nama Panjang untuk Pemeriksaan Responsif','Q0001','0','0','0','0']);
    $db->prepare('INSERT INTO app_users(username,name,password_hash) VALUES(?,?,?)')->execute(['qa_migration','Petugas QA Migrasi',password_hash('Qa!Migration-2026-Local',PASSWORD_DEFAULT)]);
    $db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute(['qa_migration_browser','synthetic']);
    echo json_encode(['database'=>$database,'nip'=>'00001','name'=>'Karyawan <Uji> & Aman','other_nip'=>'00002','local_nip'=>'LOCAL001','username'=>'qa_migration','password'=>'Qa!Migration-2026-Local'],JSON_THROW_ON_ERROR);
}catch(Throwable $error){
    if(ob_get_level())ob_end_clean();
    if($created && isset($server))$server->exec("DROP DATABASE IF EXISTS `$database`");
    fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);
}
