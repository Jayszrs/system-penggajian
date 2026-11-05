<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\Domain\{PayrollPeriod,PayrollValidationException};
use App\Services\PayrollConfirmation;

// Pure session/fingerprint/period fixtures. This script never opens a database.
$checks=0;
$check=static function(bool $condition,string $label) use(&$checks): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$reorder=static function(array $value) use(&$reorder): array {
    foreach($value as &$item)if(is_array($item))$item=$reorder($item);unset($item);
    return array_is_list($value)?$value:array_reverse($value,true);
};
$result=[
    'calculation_version'=>'accounting_v2',
    'values'=>['gapok'=>'1000000','t_jabatan'=>'200000','absensi'=>'15001','tpenghasilan'=>'1200000','tpotongan'=>'15001','tbersih'=>'1184999'],
    'snapshot'=>[
        'policy_version'=>'accounting_v2','mode'=>'master','period'=>['month'=>2,'year'=>2024],
        'rates'=>['daily'=>['amount'=>'30001','source'=>'employee'],'overtime'=>['amount'=>'15000','source'=>'employee']],
        'quantities'=>['hefektif'=>'20','hmasuk'=>'19','halpha'=>'1'],
    ],
    'issues'=>[['code'=>'manual_review','severity'=>'warning','field'=>'absensi','message'=>'Periksa pembulatan potongan.']],
];
$identity=['record_id'=>0,'nip'=>'000000001','bulan'=>2,'tahun'=>2024,'bagian'=>'TEST'];
$originalSession=$_SESSION??null;
try{
    $_SESSION=[];
    $token=PayrollConfirmation::issue($result,$identity);
    $check((bool)preg_match('/^[0-9a-f]{48}$/',$token),'Opaque confirmation token has expected entropy encoding');
    $check(PayrollConfirmation::valid($token,$result,$identity),'Issued token validates exact server calculation');
    $check(PayrollConfirmation::fingerprint($result,$identity)===PayrollConfirmation::fingerprint($reorder($result),$reorder($identity)),'Associative ordering is canonical, including nested snapshot and issue keys');
    $check(PayrollConfirmation::valid($token,$reorder($result),$reorder($identity)),'Associative ordering does not require reconfirmation');

    $changes=[];
    $changed=$result;$changed['values']['gapok']='1000001';$changes['monetary value']=$changed;
    $changed=$result;$changed['values']['tbersih']='1185000';$changes['calculated total']=$changed;
    $changed=$result;$changed['snapshot']['rates']['daily']['amount']='30002';$changes['rate snapshot']=$changed;
    $changed=$result;$changed['snapshot']['rates']['daily']['source']='override';$changes['rate provenance']=$changed;
    $changed=$result;$changed['snapshot']['period']['year']=2025;$changes['snapshot period']=$changed;
    $changed=$result;$changed['snapshot']['quantities']['hmasuk']='20';$changes['attendance snapshot']=$changed;
    $changed=$result;$changed['snapshot']['mode']='manual';$changes['calculation mode']=$changed;
    $changed=$result;$changed['calculation_version']='accounting_v3';$changes['calculation version']=$changed;
    foreach(['code'=>'changed_warning','severity'=>'error','field'=>'gapok','message'=>'Isi peringatan berubah.'] as $field=>$value){$changed=$result;$changed['issues'][0][$field]=$value;$changes['issue '.$field]=$changed;}
    $changed=$result;$changed['issues'][]=['code'=>'new_warning','severity'=>'warning','field'=>'hmasuk','message'=>'Perlu pemeriksaan tambahan.'];$changes['additional issue']=$changed;
    $changed=$result;$changed['issues']=[];$changes['removed issue']=$changed;
    foreach($changes as $label=>$changed)$check(!PayrollConfirmation::valid($token,$changed,$identity),'Changed '.$label.' invalidates confirmation');
    foreach(['record_id'=>1,'nip'=>'000000002','bulan'=>3,'tahun'=>2025,'bagian'=>'OTHER'] as $field=>$value){$changed=$identity;$changed[$field]=$value;$check(!PayrollConfirmation::valid($token,$result,$changed),'Another identity '.$field.' cannot reuse token');}
    $check(!PayrollConfirmation::valid('unknown-token',$result,$identity),'Unknown token is rejected');
    $check(!PayrollConfirmation::valid('',$result,$identity),'Missing token is rejected');

    $twoIssues=$result;$twoIssues['issues'][]=['code'=>'second_warning','severity'=>'warning','field'=>'hmasuk','message'=>'Peringatan kedua.'];
    $listToken=PayrollConfirmation::issue($twoIssues,$identity);$changed=$twoIssues;$changed['issues']=array_reverse($changed['issues']);
    $check(!PayrollConfirmation::valid($listToken,$changed,$identity),'List ordering remains meaningful rather than being sorted as map keys');

    $_SESSION['payroll_confirmations'][$token]['issued_at']=time()-899;
    $check(PayrollConfirmation::valid($token,$result,$identity),'Token remains valid within fifteen minutes');
    $_SESSION['payroll_confirmations'][$token]['issued_at']=time()-901;
    $check(!PayrollConfirmation::valid($token,$result,$identity),'Expired token is rejected');
    $fresh=PayrollConfirmation::issue($result,$identity);
    $check(!isset($_SESSION['payroll_confirmations'][$token]),'Issuing a token purges expired confirmations');
    $check(PayrollConfirmation::valid($fresh,$result,$identity),'New token validates after expiry cleanup');
    PayrollConfirmation::consume($fresh);
    $check(!PayrollConfirmation::valid($fresh,$result,$identity),'Consumed token cannot be reused');
    PayrollConfirmation::consume($fresh);
    $check(!isset($_SESSION['payroll_confirmations'][$fresh]),'Repeated consume is harmless');
    $currentSession=$_SESSION;$_SESSION=[];
    $check(!PayrollConfirmation::valid($listToken,$twoIssues,$identity),'Another session cannot use a token');$_SESSION=$currentSession;

    $_SESSION=[];$tokens=[];
    for($index=0;$index<25;$index++)$tokens[]=PayrollConfirmation::issue($result,$identity);
    $check(count(array_unique($tokens))===25,'Repeated previews issue distinct tokens');
    $check(count($_SESSION['payroll_confirmations'])===24,'Session token budget is capped at twenty-four');
    $check(!PayrollConfirmation::valid($tokens[0],$result,$identity),'Twenty-fifth issue evicts the oldest token');
    foreach(array_slice($tokens,1) as $active)$check(PayrollConfirmation::valid($active,$result,$identity),'Newer confirmation survives eviction');

    foreach([['01',1],['09',9],['12',12],[1,1],[12,12],['0002',2]] as [$raw,$expected])$check(PayrollPeriod::value($raw,'Bulan',1,12)===$expected,'Valid integer month normalizes leading zeros');
    foreach([['1900',1900],['2024',2024],['02024',2024],[9999,9999]] as [$raw,$expected])$check(PayrollPeriod::value($raw,'Tahun',1900,9999)===$expected,'Valid year preserves explicit value');
    foreach([null,'',' ',' 2','2 ','+2','-1','0','00','13','1.5',1.5,'1abc','1e1','1E1','0x0A','999999999999999999999',[],['2'],true,false] as $invalid){
        try{PayrollPeriod::value($invalid,'Bulan',1,12);throw new RuntimeException('FAIL: Invalid month unexpectedly accepted');}
        catch(PayrollValidationException $exception){$check(($exception->issues[0]['code']??'')==='invalid_period','Invalid month rejected without casting, truncation, or fallback');$check(($exception->issues[0]['field']??'')==='bulan','Invalid month identifies its input field');}
    }
    foreach(['1899','10000','2024.5','2024abc','2.024e3',[],null] as $invalid){
        try{PayrollPeriod::value($invalid,'Tahun',1900,9999);throw new RuntimeException('FAIL: Invalid year unexpectedly accepted');}
        catch(PayrollValidationException $exception){$check(($exception->issues[0]['code']??'')==='invalid_period','Invalid year is rejected without fallback');$check(($exception->issues[0]['field']??'')==='tahun','Invalid year identifies its input field');}
    }
    echo "PASS: $checks pure confirmation and strict-period checks; no database opened.\n";
}finally{
    if($originalSession===null)unset($_SESSION);else $_SESSION=$originalSession;
}
