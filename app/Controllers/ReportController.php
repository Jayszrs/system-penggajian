<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Controller,Database};
use App\Services\PayrollRepository;
use App\Domain\{PayrollFields,PrintLayout};
final class ReportController extends Controller {
    public const TYPES=['slip_karyawan'=>'Slip per Karyawan','slip_bagian'=>'Slip per Bagian','rekap_bagian'=>'Rekap Gaji per Bagian','rekap_periode'=>'Rekap Rentang Periode','rekap_periode_bagian'=>'Rekap Periode per Bagian','absensi'=>'Rekap Absensi','gapok'=>'Rekap Gaji Pokok'];
    public function index(): void {
        $db=Database::connection();$repo=new PayrollRepository();$period=$repo->period();$type=(string)($_GET['type']??'slip_karyawan');if(!isset(self::TYPES[$type]))$type='slip_karyawan';
        $isRange=in_array($type,['rekap_periode','rekap_periode_bagian'],true);$isSlip=in_array($type,['slip_karyawan','slip_bagian'],true);$employeeScope=in_array($type,['slip_karyawan','rekap_periode'],true);
        $nip=trim((string)($_GET['nip']??''));$scope=(string)($_GET[$employeeScope?'cakupan_karyawan':'cakupan_bagian']??($employeeScope?($nip!==''?'tertentu':'semua'):($period['bagian']!==''?'tertentu':'semua')));
        $startMonth=(int)($_GET['dari_bulan']??1);$startYear=(int)($_GET['dari_tahun']??$period['tahun']);$endMonth=(int)($_GET['sampai_bulan']??$period['bulan']);$endYear=(int)($_GET['sampai_tahun']??$period['tahun']);$error=null;$rows=[];$totals=[];$view=isset($_GET['view']);
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
                    foreach($rows as &$r)$r['total_struktur']=PayrollRepository::moneySum([$r['gapok'],$r['t_jabatan'],$r['t_harian'],$r['t_lembur']]);unset($r);
                    foreach(['gapok','t_jabatan','t_harian','t_lembur','total_struktur'] as $f)$totals[$f]=PayrollRepository::moneySum(array_column($rows,$f));
                }else{
                    if($isRange){$where[]='(CAST(p.tahun AS UNSIGNED)*100+CAST(p.bulan AS UNSIGNED)) BETWEEN ? AND ?';$params[]=$startYear*100+$startMonth;$params[]=$endYear*100+$endMonth;}
                    else{$where[]='CAST(p.bulan AS UNSIGNED)=? AND CAST(p.tahun AS UNSIGNED)=?';$params[]=$period['bulan'];$params[]=$period['tahun'];}
                    if($scope==='tertentu'){if($employeeScope){$where[]='p.nip=?';$params[]=$nip;}else{$where[]='COALESCE(p.department_code,k.kd_bagian)=?';$params[]=$period['bagian'];}}
                    $q=$db->prepare("SELECT p.*,COALESCE(p.employee_name,k.nama,p.nip) AS nama,COALESCE(p.department_code,k.kd_bagian,'') AS kd_bagian,COALESCE(p.department_name,b.nama_bagian,'Tanpa bagian') AS nama_bagian FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=COALESCE(p.department_code,k.kd_bagian) WHERE ".implode(' AND ',$where).' ORDER BY kd_bagian,nama,CAST(p.tahun AS UNSIGNED),CAST(p.bulan AS UNSIGNED),p.id');$q->execute($params);$rows=$q->fetchAll();
                    foreach(PayrollFields::numeric() as $f=>$label)$totals[$f]=PayrollRepository::moneySum(array_column($rows,$f));
                }
            }
        }
        $rangeLabel=$isRange?month_name($startMonth).' '.$startYear.' – '.month_name($endMonth).' '.$endYear:month_name($period['bulan']).' '.$period['tahun'];
        $employees=$db->query('SELECT nip,nama FROM karyawan ORDER BY nama')->fetchAll();
        $print=PrintLayout::metadata((string)$_SESSION['user']['name']);
        $this->view('reports',['print'=>$print,'title'=>'Slip & Laporan','section'=>'Laporan','types'=>self::TYPES,'type'=>$type,'reportTitle'=>self::TYPES[$type],'period'=>$period,'isRange'=>$isRange,'isSlip'=>$isSlip,'employeeScope'=>$employeeScope,'scope'=>$scope,'nip'=>$nip,'startMonth'=>$startMonth,'startYear'=>$startYear,'endMonth'=>$endMonth,'endYear'=>$endYear,'error'=>$error,'view'=>$view,'rows'=>$rows,'totals'=>$totals,'rangeLabel'=>$rangeLabel,'departments'=>$repo->departments(),'employees'=>$employees,'detailed'=>($_GET['rincian']??'')==='1']);
    }
}
