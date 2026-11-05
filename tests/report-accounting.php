<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Controllers\ReportController;
use App\Core\Database;
use App\Domain\PayrollFields;
use App\Services\PayrollRepository;

// This suite only SELECTs inside a read-only transaction. No fixtures or users are written.
$checks=0;$cases=0;
function reportCheck(bool $condition,string $message): void {
    global $checks;
    if(!$condition)throw new RuntimeException('Report accounting check failed: '.$message);
    $checks++;
}
function reportMoney(mixed $value): string {
    $value=(string)($value??'0');$negative=str_starts_with($value,'-');$digits=ltrim($value,'-');
    return ($negative?'-Rp ':'Rp ').implode('.',array_reverse(array_map('strrev',str_split(strrev($digits),3))));
}
function reportCell(DOMXPath $xpath,DOMNode $row,string $field): string {
    return trim($xpath->query('./td[@data-field="'.$field.'"]',$row)->item(0)?->textContent??'');
}
function reportRender(array $query): DOMXPath {
    $_GET=$query+['view'=>'1'];$_SERVER['REQUEST_URI']='/laporan?'.http_build_query($_GET);
    ob_start();try{(new ReportController())->index();$html=ob_get_contents();}finally{ob_end_clean();}
    $dom=new DOMDocument();$before=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($before);
    return new DOMXPath($dom);
}
function reportTableCheck(DOMXPath $xpath,DOMNode $table,array $expected,bool $master): void {
    $money=array_fill_keys([...array_keys(PayrollFields::INCOME),...array_keys(PayrollFields::DEDUCTIONS),...array_keys(PayrollFields::TOTALS),'t_lembur','total_tetap'],true);
    $numeric=array_fill_keys([...array_keys(PayrollFields::numeric()),'t_lembur','total_tetap'],true);
    $byKey=[];$allTotals=[];
    foreach($expected as $row){$key=$row['nip'].($master?'':':'.(int)$row['tahun'].':'.(int)$row['bulan']);$byKey[$key]=$row;foreach($numeric as $field=>$_)$allTotals[$field]=bcadd($allTotals[$field]??'0',(string)($row[$field]??0),0);}
    $group=[];$seen=[];
    foreach($xpath->query('./tbody/tr',$table) as $tr){
        $subtotal=str_contains($tr->getAttribute('class'),'report-subtotal');
        if(!$subtotal){
            $key=reportCell($xpath,$tr,'nip').($master?'':':'.(int)reportCell($xpath,$tr,'tahun').':'.(int)reportCell($xpath,$tr,'bulan'));
            reportCheck(isset($byKey[$key]),'rendered row belongs to requested scope');$row=$byKey[$key];$seen[$key]=($seen[$key]??0)+1;
            foreach($numeric as $field=>$_)$group[$field]=bcadd($group[$field]??'0',(string)($row[$field]??0),0);
        }
        foreach($xpath->query('./td[@data-field]',$tr) as $td){$field=$td->getAttribute('data-field');if(!isset($numeric[$field]))continue;
            $expectedValue=$subtotal?($group[$field]??'0'):($row[$field]??'0');
            $text=$subtotal&&$master&&in_array($field,['t_harian','t_lembur'],true)?'—':(isset($money[$field])?reportMoney($expectedValue):$expectedValue);
            reportCheck(trim($td->textContent)===$text,($subtotal?'subtotal':'detail').' '.$field);
            reportCheck(str_contains($td->getAttribute('class'),'text-end'),'numeric alignment '.$field);
        }
        if($subtotal)$group=[];
    }
    reportCheck(count($seen)===count($expected)&&array_sum($seen)===count($expected),'each requested transaction appears exactly once');
    foreach($xpath->query('./tfoot/tr/td[@data-field]',$table) as $td){$field=$td->getAttribute('data-field');if(!isset($numeric[$field]))continue;
        $value=$allTotals[$field]??'0';$text=$master&&in_array($field,['t_harian','t_lembur'],true)?'—':(isset($money[$field])?reportMoney($value):$value);
        reportCheck(trim($td->textContent)===$text,'grand total '.$field);
    }
}
try{
    $db=Database::connection();$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    $_SESSION=['user'=>['id'=>0,'name'=>'Accounting test','username'=>'audit'],'csrf'=>'read-only-accounting'];
    $codeSql=PayrollRepository::departmentCodeSql();$employeeSql=PayrollRepository::employeeNameSql();$departmentSql=PayrollRepository::departmentNameSql();
    $identity=$db->prepare("SELECT $codeSql department_code,$employeeSql employee_name,$departmentSql department_name FROM (SELECT ? department_code,? department_name,? employee_name,'00001' nip) p LEFT JOIN (SELECT 'NEW' kd_bagian,'Current employee' nama) k ON 1=1 LEFT JOIN (SELECT 'OLD' kd_bagian,'Historical department' nama_bagian UNION ALL SELECT 'NEW','Current department') b ON b.kd_bagian=$codeSql");
    foreach([
        [['OLD',null,null],['OLD','Current employee','Historical department']],
        [['OLD','',''],['OLD','Current employee','Historical department']],
        [['','',''],['NEW','Current employee','Current department']],
        [[null,null,'Snapshot employee'],['NEW','Snapshot employee','Current department']],
    ] as [$input,$expected]){$identity->execute($input);reportCheck(array_values($identity->fetch())===$expected,'snapshot identity and empty fallback');$cases++;}
    $payroll=$db->query('SELECT * FROM penghasilan ORDER BY id')->fetchAll();$employees=$db->query('SELECT * FROM karyawan ORDER BY id')->fetchAll();
    $byEmployee=[];foreach($employees as $employee)$byEmployee[$employee['nip']]=$employee;
    $periods=[];foreach($payroll as $row)$periods[(int)$row['tahun'].':'.(int)$row['bulan']]=['bulan'=>(int)$row['bulan'],'tahun'=>(int)$row['tahun']];
    foreach($periods as $period){
        $rows=array_values(array_filter($payroll,static fn($row)=>(int)$row['bulan']===$period['bulan']&&(int)$row['tahun']===$period['tahun']));
        foreach(['rekap_bagian','absensi','slip_karyawan','slip_bagian'] as $type){
            $xpath=reportRender(['type'=>$type,...$period]);
            if(str_starts_with($type,'slip')){
                $slips=$xpath->query('//article[contains(@class,"slip")]');reportCheck($slips->length===count($rows),'slip count');$byNip=[];foreach($rows as $row)$byNip[$row['nip']]=$row;
                foreach($slips as $slip){$nip=$slip->getAttribute('data-slip-nip');reportCheck(isset($byNip[$nip]),'slip scope');$row=$byNip[$nip];
                    foreach([...array_keys(PayrollFields::INCOME),...array_keys(PayrollFields::DEDUCTIONS),...array_keys(PayrollFields::TOTALS)] as $field){
                        $amount=$xpath->query('.//*[@data-slip-field="'.$field.'"]',$slip)->item(0);
                        $expected=preg_replace('/^(-?)Rp /','$1',reportMoney($row[$field]));
                        reportCheck(trim($amount?->textContent??'')===$expected,'slip component '.$field);
                    }
                }
            }else{reportTableCheck($xpath,$xpath->query('//table[contains(@class,"report-table")]')->item(0),$rows,false);}
            $cases++;
        }
        $department=$rows[0]['department_code']??$byEmployee[$rows[0]['nip']]['kd_bagian'];$selected=array_values(array_filter($rows,static fn($row)=>($row['department_code']??$byEmployee[$row['nip']]['kd_bagian'])===$department));
        $xpath=reportRender(['type'=>'rekap_bagian',...$period,'cakupan_bagian'=>'tertentu','bagian'=>$department,'rincian'=>'1']);
        foreach($xpath->query('//table[contains(@class,"report-table")]') as $table)reportTableCheck($xpath,$table,$selected,false);$cases++;
        $nip=$rows[0]['nip'];$xpath=reportRender(['type'=>'rekap_periode',...$period,'dari_bulan'=>$period['bulan'],'dari_tahun'=>$period['tahun'],'sampai_bulan'=>$period['bulan'],'sampai_tahun'=>$period['tahun'],'cakupan_karyawan'=>'tertentu','nip'=>$nip]);
        reportTableCheck($xpath,$xpath->query('//table[contains(@class,"report-table")]')->item(0),array_values(array_filter($rows,static fn($row)=>$row['nip']===$nip)),false);$cases++;
    }
    foreach($employees as &$employee)$employee['total_tetap']=bcadd((string)($employee['gapok']??0),(string)($employee['t_jabatan']??0),0);unset($employee);
    $xpath=reportRender(['type'=>'gapok','rincian'=>'1']);reportTableCheck($xpath,$xpath->query('//table[contains(@class,"report-table")]')->item(0),$employees,true);reportCheck($xpath->query('//table[contains(@class,"report-table")]')->length===1,'master rates do not render payroll print sections');$cases++;
    foreach(['rekap_periode','rekap_periode_bagian'] as $type){$selected=array_values(array_filter($payroll,static fn($row)=>(int)$row['tahun']*100+(int)$row['bulan']>=202412&&(int)$row['tahun']*100+(int)$row['bulan']<=202502));$xpath=reportRender(['type'=>$type,'dari_bulan'=>12,'dari_tahun'=>2024,'sampai_bulan'=>2,'sampai_tahun'=>2025]);if($selected)reportTableCheck($xpath,$xpath->query('//table[contains(@class,"report-table")]')->item(0),$selected,false);else reportCheck($xpath->query('//table[contains(@class,"report-table")]')->length===0,'empty cross-year scope');$cases++;}
    $xpath=reportRender(['type'=>'rekap_bagian','bulan'=>1,'tahun'=>9999]);reportCheck($xpath->query('//table[contains(@class,"report-table")]')->length===0,'empty period has no misleading amount table');$cases++;
    $xpath=reportRender(['type'=>'rekap_periode','dari_bulan'=>12,'dari_tahun'=>2025,'sampai_bulan'=>1,'sampai_tahun'=>2025]);reportCheck($xpath->query('//*[contains(@class,"alert-danger")]')->length===1,'reversed range rejected');$cases++;
    foreach(['1abc','1.5','0','13',['1']] as $invalid){try{reportRender(['type'=>'rekap_periode','dari_bulan'=>$invalid]);reportCheck(false,'malformed range rejected');}catch(InvalidArgumentException){reportCheck(true,'malformed range rejected');}$cases++;}
    foreach([['type'=>['gapok']],['type'=>'slip_karyawan','nip'=>['0001']],['type'=>'rekap_bagian','cakupan_bagian'=>['semua']]] as $invalid){try{reportRender($invalid);reportCheck(false,'array input rejected');}catch(InvalidArgumentException){reportCheck(true,'array input rejected');}$cases++;}
    $db->rollBack();echo "PASS: $checks report accounting checks across $cases scopes; read-only, private values omitted.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
