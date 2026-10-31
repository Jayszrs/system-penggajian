<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Controller,Database};
use App\Services\PayrollRepository;
use App\Domain\PayrollFields;
final class DashboardController extends Controller {
    public function index(): void {
        $db=Database::connection();$repo=new PayrollRepository();$period=$repo->period();[$where,$params]=$repo::scope($period);
        $sums=implode(',',array_map(static fn($c)=>"COALESCE(SUM(p.`$c`),0) AS `$c`",array_keys(PayrollFields::numeric())));
        $q=$db->prepare("SELECT COUNT(*) AS transactions,$sums FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip WHERE $where");$q->execute($params);$totals=$q->fetch();
        $q=$db->prepare('SELECT COUNT(*) FROM karyawan'.($period['bagian']!==''?' WHERE kd_bagian=?':''));$q->execute($period['bagian']!==''?[$period['bagian']]:[]);$employeeCount=(int)$q->fetchColumn();
        $annualWhere='CAST(p.tahun AS UNSIGNED)=?';$annualParams=[$period['tahun']];
        if($period['bagian']!==''){$annualWhere.=' AND COALESCE(p.department_code,k.kd_bagian)=?';$annualParams[]=$period['bagian'];}
        $q=$db->prepare("SELECT CAST(p.bulan AS UNSIGNED) AS month, SUM(p.tbersih) AS amount,COUNT(*) AS transactions FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip WHERE $annualWhere GROUP BY CAST(p.bulan AS UNSIGNED)");$q->execute($annualParams);$annual=array_fill(1,12,null);
        foreach($q as $r)if(isset($annual[(int)$r['month']]) || array_key_exists((int)$r['month'],$annual))$annual[(int)$r['month']]=$r['amount'];
        $q=$db->prepare("SELECT COALESCE(p.department_code,k.kd_bagian,'') AS code,COALESCE(p.department_name,b.nama_bagian,'Tanpa bagian') AS name,COUNT(DISTINCT p.nip) AS employees,COUNT(*) AS transactions,COALESCE(SUM(p.tpenghasilan),0) AS gross,COALESCE(SUM(p.tpotongan),0) AS deductions,COALESCE(SUM(p.tbersih),0) AS net FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=COALESCE(p.department_code,k.kd_bagian) WHERE $where GROUP BY COALESCE(p.department_code,k.kd_bagian,''),COALESCE(p.department_name,b.nama_bagian,'Tanpa bagian') ORDER BY code");$q->execute($params);$distribution=$q->fetchAll();
        $q=$db->prepare("SELECT p.id,p.nip,COALESCE(p.employee_name,k.nama,p.nip) AS nama,COALESCE(p.department_name,b.nama_bagian,'—') AS nama_bagian,p.tpenghasilan,p.tpotongan,p.tbersih FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=COALESCE(p.department_code,k.kd_bagian) WHERE $where ORDER BY p.id DESC LIMIT 5");$q->execute($params);$recent=$q->fetchAll();
        $migration=$db->query('SELECT status,created_at FROM migration_runs ORDER BY id DESC LIMIT 1')->fetch();
        $this->view('dashboard',['title'=>'Dashboard Penggajian','section'=>'Ringkasan','period'=>$period,'departments'=>$repo->departments(),'totals'=>$totals,'employeeCount'=>$employeeCount,'annual'=>$annual,'distribution'=>$distribution,'recent'=>$recent,'migration'=>$migration]);
    }
    public function migration(): void {
        $db=Database::connection();
        $this->view('migration',['title'=>'Data & Migrasi','section'=>'Sistem','runs'=>$db->query('SELECT * FROM migration_runs ORDER BY id DESC LIMIT 10')->fetchAll(),'tables'=>$db->query('SELECT table_name,row_count,content_sha256 FROM legacy_tables ORDER BY table_name')->fetchAll()]);
    }
    public function archive(): void {
        $db=Database::connection();$table=(string)($_GET['table']??'');$q=$db->prepare('SELECT * FROM legacy_tables WHERE table_name=?');$q->execute([$table]);$metadata=$q->fetch();
        if(!$metadata){http_response_code(404);$this->view('error',['errorMessage'=>'Tabel arsip tidak ditemukan.']);return;}
        $page=max(1,(int)($_GET['page']??1));$offset=($page-1)*25;
        $q=$db->prepare("SELECT source_ordinal,payload FROM legacy_records WHERE table_name=? ORDER BY source_ordinal LIMIT 25 OFFSET $offset");$q->execute([$table]);
        $this->view('archive',['title'=>'Arsip '.$table,'section'=>'Data & Migrasi','metadata'=>$metadata,'rows'=>$q->fetchAll(),'page'=>$page]);
    }
}
