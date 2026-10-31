<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use App\Domain\{PayrollPeriod,PayrollValidationException};
final class PayrollRepository {
    public function period(): array {
        $last=Database::connection()->query("SELECT bulan,tahun FROM penghasilan WHERE bulan REGEXP '^[0-9]{1,2}$' AND tahun REGEXP '^[0-9]{4}$' AND CAST(bulan AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(tahun AS UNSIGNED) BETWEEN 1900 AND 9999 ORDER BY CAST(tahun AS UNSIGNED) DESC,CAST(bulan AS UNSIGNED) DESC,id DESC LIMIT 1")->fetch();
        $month=PayrollPeriod::value(array_key_exists('bulan',$_GET)?$_GET['bulan']:($last['bulan']??date('n')),'Bulan',1,12);
        $year=PayrollPeriod::value(array_key_exists('tahun',$_GET)?$_GET['tahun']:($last['tahun']??date('Y')),'Tahun',1900,9999);
        $raw=$_GET['bagian']??'';
        if(!is_string($raw))throw new PayrollValidationException('Bagian tidak valid.','bagian','invalid_department');
        $department=trim($raw);
        if($department!==''){
            $q=Database::connection()->prepare('SELECT 1 FROM bagian WHERE kd_bagian=? UNION ALL SELECT 1 FROM penghasilan WHERE department_code=? LIMIT 1');$q->execute([$department,$department]);
            if(!$q->fetchColumn())throw new PayrollValidationException('Bagian yang dipilih tidak ditemukan.','bagian','invalid_department');
        }
        return ['bulan'=>$month,'tahun'=>$year,'bagian'=>$department];
    }
    public function departments(): array { return Database::connection()->query('SELECT * FROM bagian ORDER BY kd_bagian')->fetchAll(); }
    /** Suggestions only: any valid year may still be entered directly. */
    public function years(array $selected = []): array {
        $years = Database::connection()->query("SELECT DISTINCT CAST(tahun AS UNSIGNED) FROM penghasilan WHERE tahun REGEXP '^[0-9]{4}$' AND CAST(tahun AS UNSIGNED) BETWEEN 1900 AND 9999")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ([(int)date('Y'), ...$selected] as $year) {
            if ((is_int($year) || is_string($year)) && ctype_digit((string)$year) && (int)$year >= 1900 && (int)$year <= 9999) $years[] = (int)$year;
        }
        $years = array_values(array_unique(array_map('intval', $years))); rsort($years, SORT_NUMERIC);
        return $years;
    }
    public function find(int $id,bool $forUpdate=false): array|false {
        $code=self::departmentCodeSql();$name=self::employeeNameSql();$department=self::departmentNameSql();
        $q=Database::connection()->prepare("SELECT p.*,$name AS nama,$code AS kd_bagian,$department AS nama_bagian FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=$code WHERE p.id=?".($forUpdate?' FOR UPDATE':''));$q->execute([$id]);return $q->fetch();
    }
    public static function departmentCodeSql(string $alias='p',string $employeeAlias='k'): string { return "COALESCE(NULLIF($alias.department_code,''),$employeeAlias.kd_bagian,'')"; }
    public static function employeeNameSql(string $alias='p',string $employeeAlias='k'): string { return "COALESCE(NULLIF($alias.employee_name,''),$employeeAlias.nama,$alias.nip)"; }
    public static function departmentNameSql(string $alias='p',string $departmentAlias='b'): string { return "COALESCE(NULLIF($alias.department_name,''),$departmentAlias.nama_bagian,'Tanpa bagian')"; }
    public static function scope(array $period, string $alias='p'): array {
        $where="CAST($alias.bulan AS UNSIGNED)=? AND CAST($alias.tahun AS UNSIGNED)=?"; $params=[$period['bulan'],$period['tahun']];
        if ($period['bagian']!=='') {$where.=' AND '.self::departmentCodeSql($alias).'=?';$params[]=$period['bagian'];}
        return [$where,$params];
    }
    public static function moneySum(array $values): string { $sum='0';foreach($values as $v)$sum=bcadd($sum,(string)($v??'0'),0);return $sum; }
}
