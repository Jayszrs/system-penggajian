<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Controller,Database};
use App\Services\PayrollRepository;
use App\Domain\{PayrollFields,PayrollPeriod,PayrollValidationException,PrintLayout};
final class ReportController extends Controller {
    public const TYPES=['slip_karyawan'=>'Slip per Karyawan','slip_bagian'=>'Slip per Bagian','rekap_bagian'=>'Rekap Gaji per Bagian','rekap_periode'=>'Rekap Rentang Periode','rekap_periode_bagian'=>'Rekap Periode per Bagian','absensi'=>'Rekap Absensi','gapok'=>'Rekap Gaji Pokok'];
    public function index(): void {
        $db=Database::connection();$repo=new PayrollRepository();$period=$repo->period();$type=$_GET['type']??'slip_karyawan';
        if(!is_string($type))throw new PayrollValidationException('Jenis laporan tidak valid.','type','invalid_report_type');
        if(!isset(self::TYPES[$type]))$type='slip_karyawan';
        $isRange=in_array($type,['rekap_periode','rekap_periode_bagian'],true);$isSlip=in_array($type,['slip_karyawan','slip_bagian'],true);$employeeScope=in_array($type,['slip_karyawan','rekap_periode'],true);
        $nip=$_GET['nip']??'';
        if(!is_string($nip))throw new PayrollValidationException('NIP karyawan tidak valid.','nip','invalid_employee');
        $nip=trim($nip);$scopeField=$employeeScope?'cakupan_karyawan':'cakupan_bagian';$scope=$_GET[$scopeField]??($employeeScope?($nip!==''?'tertentu':'semua'):($period['bagian']!==''?'tertentu':'semua'));
        if(!is_string($scope))throw new PayrollValidationException('Cakupan laporan tidak valid.',$scopeField,'invalid_report_scope');
        $startMonth=1;$startYear=$period['tahun'];$endMonth=$period['bulan'];$endYear=$period['tahun'];$error=null;$rows=[];$totals=[];$view=isset($_GET['view']);
        if($isRange){
            $startMonth=PayrollPeriod::value($_GET['dari_bulan']??1,'Bulan awal',1,12);
            $startYear=PayrollPeriod::value($_GET['dari_tahun']??$period['tahun'],'Tahun awal',1900,9999);
            $endMonth=PayrollPeriod::value($_GET['sampai_bulan']??$period['bulan'],'Bulan akhir',1,12);
            $endYear=PayrollPeriod::value($_GET['sampai_tahun']??$period['tahun'],'Tahun akhir',1900,9999);
        }
        if($view){
            if(!in_array($scope,['semua','tertentu'],true))$error='Pilih cakupan laporan yang valid.';
            elseif($isRange && ($startMonth<1||$startMonth>12||$endMonth<1||$endMonth>12||$startYear<1900||$startYear>9999||$endYear<1900||$endYear>9999||$startYear*100+$startMonth>$endYear*100+$endMonth))$error='Rentang periode tidak valid. Periode awal tidak boleh melewati periode akhir.';
            elseif($scope==='tertentu' && $employeeScope && $nip==='')$error='Isi NIP karyawan untuk cakupan karyawan tertentu.';
            elseif($scope==='tertentu' && !$employeeScope && $period['bagian']==='')$error='Pilih bagian untuk cakupan bagian tertentu.';
            if(!$error){
                $where=[];$params=[];
                if($type==='gapok'){
                    if($scope==='tertentu'){$where[]='k.kd_bagian=?';$params[]=$period['bagian'];}
                    $q=$db->prepare('SELECT k.*,b.nama_bagian FROM karyawan k LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY k.kd_bagian,k.nama');$q->execute($params);$rows=$q->fetchAll();
                    foreach($rows as &$r)$r['total_tetap']=PayrollRepository::moneySum([$r['gapok'],$r['t_jabatan']]);unset($r);
                    foreach(['gapok','t_jabatan','total_tetap'] as $f)$totals[$f]=PayrollRepository::moneySum(array_column($rows,$f));
                }else{
                    if($isRange){$where[]='(CAST(p.tahun AS UNSIGNED)*100+CAST(p.bulan AS UNSIGNED)) BETWEEN ? AND ?';$params[]=$startYear*100+$startMonth;$params[]=$endYear*100+$endMonth;}
                    else{$where[]='CAST(p.bulan AS UNSIGNED)=? AND CAST(p.tahun AS UNSIGNED)=?';$params[]=$period['bulan'];$params[]=$period['tahun'];}
                    $departmentCode=PayrollRepository::departmentCodeSql();$employeeName=PayrollRepository::employeeNameSql();$departmentName=PayrollRepository::departmentNameSql();
                    if($scope==='tertentu'){if($employeeScope){$where[]='p.nip=?';$params[]=$nip;}else{$where[]="$departmentCode=?";$params[]=$period['bagian'];}}
                    $q=$db->prepare("SELECT p.*,$employeeName AS nama,$departmentCode AS kd_bagian,$departmentName AS nama_bagian FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=$departmentCode WHERE ".implode(' AND ',$where).' ORDER BY kd_bagian,nama,CAST(p.tahun AS UNSIGNED),CAST(p.bulan AS UNSIGNED),p.id');$q->execute($params);$rows=$q->fetchAll();
                    foreach(PayrollFields::numeric() as $f=>$label)$totals[$f]=PayrollRepository::moneySum(array_column($rows,$f));
                }
            }
        }
        $rangeLabel=$isRange?month_name($startMonth).' '.$startYear.' – '.month_name($endMonth).' '.$endYear:month_name($period['bulan']).' '.$period['tahun'];
        $employees=$db->query('SELECT nip,nama FROM karyawan ORDER BY nama')->fetchAll();
        $detailed=!$isSlip&&!in_array($type,['gapok','absensi'],true)&&($_GET['rincian']??'')==='1';
        $print=PrintLayout::metadata((string)$_SESSION['user']['name']);
        $yearOptions=$repo->years([$period['tahun'],$startYear,$endYear]);
        $this->view('reports',['yearOptions'=>$yearOptions,'print'=>$print,'title'=>'Slip & Laporan','section'=>'Laporan','types'=>self::TYPES,'type'=>$type,'reportTitle'=>self::TYPES[$type],'period'=>$period,'isRange'=>$isRange,'isSlip'=>$isSlip,'employeeScope'=>$employeeScope,'scope'=>$scope,'nip'=>$nip,'startMonth'=>$startMonth,'startYear'=>$startYear,'endMonth'=>$endMonth,'endYear'=>$endYear,'error'=>$error,'view'=>$view,'rows'=>$rows,'totals'=>$totals,'rangeLabel'=>$rangeLabel,'departments'=>$repo->departments(),'employees'=>$employees,'detailed'=>$detailed]);
    }
}
