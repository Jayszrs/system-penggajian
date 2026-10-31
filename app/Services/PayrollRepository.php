<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
final class PayrollRepository {
    public function period(): array {
        $last=Database::connection()->query("SELECT bulan,tahun FROM penghasilan WHERE bulan REGEXP '^[0-9]+$' AND tahun REGEXP '^[0-9]{4}$' ORDER BY CAST(tahun AS UNSIGNED) DESC,CAST(bulan AS UNSIGNED) DESC LIMIT 1")->fetch();
        $rawMonth=(string)($_GET['bulan']??$last['bulan']??date('n'));$rawYear=(string)($_GET['tahun']??$last['tahun']??date('Y'));
        $month=ctype_digit($rawMonth)?filter_var((string)(int)$rawMonth,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]):false;
        $year=ctype_digit($rawYear)?filter_var((string)(int)$rawYear,FILTER_VALIDATE_INT,['options'=>['min_range'=>1900,'max_range'=>9999]]):false;
        return ['bulan'=>$month?: (int)date('n'),'tahun'=>$year?:(int)date('Y'),'bagian'=>trim((string)($_GET['bagian']??''))];
    }
    public function departments(): array { return Database::connection()->query('SELECT * FROM bagian ORDER BY kd_bagian')->fetchAll(); }
    public function find(int $id): array|false {
        $q=Database::connection()->prepare('SELECT p.*,COALESCE(p.employee_name,k.nama) AS nama,COALESCE(p.department_code,k.kd_bagian) AS kd_bagian,COALESCE(p.department_name,b.nama_bagian) AS nama_bagian FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian WHERE p.id=?');$q->execute([$id]);return $q->fetch();
    }
    public static function scope(array $period, string $alias='p'): array {
        $where="CAST($alias.bulan AS UNSIGNED)=? AND CAST($alias.tahun AS UNSIGNED)=?"; $params=[$period['bulan'],$period['tahun']];
        if ($period['bagian']!=='') {$where.=" AND COALESCE($alias.department_code,k.kd_bagian)=?";$params[]=$period['bagian'];}
        return [$where,$params];
    }
    public static function moneySum(array $values): string { $sum='0';foreach($values as $v)$sum=bcadd($sum,(string)($v??'0'),0);return $sum; }
}
