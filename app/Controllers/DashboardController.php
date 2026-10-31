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
        $q=$db->prepare("SELECT COUNT(*) AS transactions,COUNT(DISTINCT p.nip) AS employees,$sums FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip WHERE $where");$q->execute($params);$totals=$q->fetch();
        $employeeCount=(int)$totals['employees'];
        $departmentCode=$repo::departmentCodeSql();
        $annualWhere='CAST(p.tahun AS UNSIGNED)=?';$annualParams=[$period['tahun']];
        if($period['bagian']!==''){$annualWhere.=" AND $departmentCode=?";$annualParams[]=$period['bagian'];}
        $q=$db->prepare("SELECT CAST(p.bulan AS UNSIGNED) AS month, SUM(p.tbersih) AS amount,COUNT(*) AS transactions FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip WHERE $annualWhere GROUP BY CAST(p.bulan AS UNSIGNED)");$q->execute($annualParams);$annual=array_fill(1,12,null);
        foreach($q as $r)if(isset($annual[(int)$r['month']]) || array_key_exists((int)$r['month'],$annual))$annual[(int)$r['month']]=$r['amount'];
        // A rename must not split one department into multiple accounting groups.
        // Master names label the chart; historical transaction snapshots remain intact.
        $q=$db->prepare("WITH scoped AS (
            SELECT p.*,$departmentCode AS scope_code
            FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip WHERE $where
        ), snapshot_names AS (
            SELECT scope_code,department_name,
                ROW_NUMBER() OVER (PARTITION BY scope_code ORDER BY id DESC) AS name_order
            FROM scoped WHERE NULLIF(department_name,'') IS NOT NULL
        )
        SELECT s.scope_code AS code,
            COALESCE(MAX(NULLIF(b.nama_bagian,'')),MAX(n.department_name),'Tanpa bagian') AS name,
            COUNT(DISTINCT s.nip) AS employees,COUNT(*) AS transactions,
            COALESCE(SUM(s.tpenghasilan),0) AS gross,
            COALESCE(SUM(s.tpotongan),0) AS deductions,
            COALESCE(SUM(s.tbersih),0) AS net
        FROM scoped s LEFT JOIN bagian b ON b.kd_bagian=s.scope_code
        LEFT JOIN snapshot_names n ON n.scope_code=s.scope_code AND n.name_order=1
        GROUP BY s.scope_code ORDER BY code");$q->execute($params);$distribution=$q->fetchAll();
        $employeeName=$repo::employeeNameSql();$departmentName=$repo::departmentNameSql();
        $q=$db->prepare("SELECT p.id,p.nip,$employeeName AS nama,$departmentName AS nama_bagian,p.tpenghasilan,p.tpotongan,p.tbersih FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=$departmentCode WHERE $where ORDER BY p.id DESC LIMIT 5");$q->execute($params);$recent=$q->fetchAll();
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
