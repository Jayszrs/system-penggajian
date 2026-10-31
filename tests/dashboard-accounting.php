<?php
declare(strict_types=1);
// Read-only reconciliation of rendered dashboard values against source components.
require dirname(__DIR__).'/app/bootstrap.php';

use App\Controllers\DashboardController;
use App\Core\Database;
use App\Domain\PayrollFields;

$checks=0;$scopeCount=0;
function dashboardCheck(bool $condition,string $label): void {
    global $checks;
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function dashboardHtml(array $period): array {
    $_GET=$period;
    ob_start();
    try{(new DashboardController())->index();$html=ob_get_contents();}
    finally{ob_end_clean();}
    $document=new DOMDocument();$document->loadHTML($html,LIBXML_NOERROR|LIBXML_NOWARNING);
    $xpath=new DOMXPath($document);
    $cards=$xpath->query('//article[contains(concat(" ",normalize-space(@class)," ")," stat-card ")]');
    $values=[];
    foreach($cards as $card)$values[]=trim($xpath->evaluate('string(.//*[contains(concat(" ",normalize-space(@class)," ")," stat-value ")])',$card));
    $data=json_decode($xpath->evaluate('string(//script[@id="dashboard-data"])'),true,512,JSON_THROW_ON_ERROR);
    return [$values,$data,$xpath];
}

$db=Database::connection();
// The test cannot write to either the operational database or its archive.
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try{
    $rows=$db->query('SELECT p.*,k.kd_bagian AS master_code FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip ORDER BY p.id DESC')->fetchAll();
    $masterNames=[];foreach($db->query('SELECT kd_bagian,nama_bagian FROM bagian') as $department)$masterNames[$department['kd_bagian']]=$department['nama_bagian'];
    $periods=[];
    foreach($rows as &$row){
        $row['resolved_code']=($row['department_code']??'')!==''?$row['department_code']:($row['master_code']??'');
        $periods[(int)$row['tahun'].'-'.(int)$row['bulan']]=['bulan'=>(int)$row['bulan'],'tahun'=>(int)$row['tahun']];
    }unset($row);
    // Always exercise a genuinely empty period, even when the source grows later.
    for($emptyYear=9999;$emptyYear>=1900;$emptyYear--)if(!isset($periods[$emptyYear.'-1']))break;
    dashboardCheck($emptyYear>=1900,'An empty period is available for reconciliation');
    $periods['empty']=['bulan'=>1,'tahun'=>$emptyYear];
    foreach($periods as $period){
        foreach(['',...array_keys($masterNames)] as $department){
            $scopeCount++;
            $filtered=array_values(array_filter($rows,static fn($row)=>(int)$row['bulan']===$period['bulan']&&(int)$row['tahun']===$period['tahun']&&($department===''||$row['resolved_code']===$department)));
            $employees=[];$expected=['gross'=>'0','deductions'=>'0','net'=>'0'];$groups=[];$latestNames=[];
            foreach($filtered as $row){
                $employees[$row['nip']]=true;$code=$row['resolved_code'];
                $groups[$code]??=['employees'=>[],'transactions'=>0,'gross'=>'0','deductions'=>'0','net'=>'0'];
                $groups[$code]['employees'][$row['nip']]=true;$groups[$code]['transactions']++;
                if(!isset($latestNames[$code])&&($row['department_name']??'')!=='')$latestNames[$code]=$row['department_name'];
                foreach(['gross'=>PayrollFields::INCOME,'deductions'=>PayrollFields::DEDUCTIONS] as $kind=>$fields){
                    foreach($fields as $field=>$label){
                        $expected[$kind]=bcadd($expected[$kind],(string)($row[$field]??'0'),0);
                        $groups[$code][$kind]=bcadd($groups[$code][$kind],(string)($row[$field]??'0'),0);
                    }
                }
                $expected['net']=bcadd($expected['net'],(string)($row['tbersih']??'0'),0);
                $groups[$code]['net']=bcadd($groups[$code]['net'],(string)($row['tbersih']??'0'),0);
            }
            [$cards,$data,$xpath]=dashboardHtml([...$period,'bagian'=>$department]);
            dashboardCheck($cards===[(string)count($employees),money($expected['gross']),money($expected['deductions']),money($expected['net'])],'Rendered cards match independently summed components and scoped recipients');
            dashboardCheck(bcsub($expected['gross'],$expected['deductions'],0)===$expected['net'],'Scoped gross less deductions equals net');
            dashboardCheck(count($data['distribution'])===count($groups),'One distribution entry per department code');
            $seen=[];
            foreach($data['distribution'] as $actual){
                $code=$actual['code'];dashboardCheck(!isset($seen[$code])&&isset($groups[$code]),'Distribution codes are unique and in scope');$seen[$code]=true;
                $group=$groups[$code];
                dashboardCheck((int)$actual['employees']===count($group['employees'])&&(int)$actual['transactions']===$group['transactions'],'Distribution recipient and transaction counts match source');
                foreach(['gross','deductions','net'] as $field)dashboardCheck((string)$actual[$field]===$group[$field],'Distribution amount matches independently summed source components');
                $name=($masterNames[$code]??'')!==''?$masterNames[$code]:($latestNames[$code]??'Tanpa bagian');
                dashboardCheck($actual['name']===$name,'Distribution label follows master with latest snapshot fallback');
            }
            $expectedAnnual=array_fill(1,12,null);
            foreach($rows as $row)if((int)$row['tahun']===$period['tahun']&&($department===''||$row['resolved_code']===$department)){
                $month=(int)$row['bulan'];if($month>=1&&$month<=12)$expectedAnnual[$month]=bcadd($expectedAnnual[$month]??'0',(string)($row['tbersih']??'0'),0);
            }
            dashboardCheck($data['annual']===array_values($expectedAnnual),'Annual graph matches source net amounts for year and department');
            $link=$xpath->query('//a[contains(concat(" ",normalize-space(@class)," ")," panel-text-link ")]')->item(0);
            parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
            dashboardCheck(($query['rincian']??'')==='1'&&(int)$query['bulan']===$period['bulan']&&(int)$query['tahun']===$period['tahun']&&($query['bagian']??'')===$department,'All-components link preserves scope and opens detailed report');
            dashboardCheck(str_contains($xpath->evaluate('string(//div[contains(concat(" ",normalize-space(@class)," ")," components-heading ")])'),'6 dari 10 komponen'),'Component count describes the six visible components');
        }
    }
    echo "PASS: $checks read-only dashboard checks across $scopeCount period/department scopes, including empty scopes.\n";
}finally{$db->rollBack();}
