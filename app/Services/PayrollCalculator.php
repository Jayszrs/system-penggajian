<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use App\Domain\PayrollFields;
use InvalidArgumentException;

final class PayrollCalculator {
    /** SQL numeric(18,0) conversion rounds halves away from zero. */
    public static function round(string $value): string {
        if(bccomp($value,'0',4)<0)return bcsub('0',bcadd(bcsub('0',$value,4),'0.5',0),0);
        return bcadd($value,'0.5',0);
    }
    public static function number(mixed $value,string $label,bool $nullable=true): ?string {
        if($value===null||$value===''){if($nullable)return null;return '0';}
        $s=trim((string)$value);
        if(!preg_match('/^-?\d{1,18}$/',$s))throw new InvalidArgumentException("$label harus bilangan bulat rupiah atau jumlah, maksimal 18 digit.");
        return bcadd($s,'0',0);
    }
    public function validateAttendance(array $v): void {
        foreach(PayrollFields::ATTENDANCE as $field=>$label){if(bccomp((string)($v[$field]??0),'0',0)<0)throw new InvalidArgumentException("$label tidak boleh negatif.");}
        if(bccomp((string)($v['hefektif']??0),'0',0)<=0)throw new InvalidArgumentException('Hari efektif harus lebih dari nol.');
        $sum=PayrollRepository::moneySum([$v['hmasuk']??0,$v['hijin']??0,$v['halpha']??0]);
        if(bccomp($sum,(string)$v['hefektif'],0)>0)throw new InvalidArgumentException('Jumlah masuk kerja, izin/alpha, dan sakit tidak boleh melebihi hari efektif.');
    }
    public function totals(array $values): array {
        $gross='0';foreach(PayrollFields::INCOME as $f=>$label)$gross=bcadd($gross,(string)($values[$f]??0),4);
        $deductions='0';foreach(PayrollFields::DEDUCTIONS as $f=>$label)$deductions=bcadd($deductions,(string)($values[$f]??0),4);
        return [...$values,'tpenghasilan'=>self::round($gross),'tpotongan'=>self::round($deductions),'tbersih'=>self::round(bcsub($gross,$deductions,4))];
    }
    public function fromInput(array $input,bool $loadDefaults=false): array {
        $values=[];foreach(PayrollFields::numeric() as $f=>$label)$values[$f]=self::number($input[$f]??null,$label);
        $this->validateAttendance($values);
        if($loadDefaults){
            $db=Database::connection();$q=$db->prepare('SELECT * FROM karyawan WHERE nip=?');$q->execute([trim((string)($input['nip']??''))]);$employee=$q->fetch();
            if(!$employee)throw new InvalidArgumentException('Pilih karyawan yang terdaftar terlebih dahulu.');
            $rate=static function(string $table,string $field,string $key) use($db): string {$q=$db->prepare("SELECT jumlah FROM `$table` WHERE `$field`=?");$q->execute([$key]);return (string)($q->fetchColumn()?:'0');};
            $values['gapok']=$employee['gapok'];$values['t_jabatan']=$employee['t_jabatan'];
            $values['t_harian']=bcmul((string)($employee['t_harian']??0),(string)$values['hefektif'],4);
            $values['t_konsumsi']=bcmul($rate('tunj_konsumsi','kd_bagian',$employee['kd_bagian']),(string)($values['hmasuk']??0),4);
            $values['ulembur']=bcmul((string)($employee['t_lembur']??0),(string)($values['lembur']??0),4);
            $values['uinfal']=bcmul($rate('tunj_infal','kd_bagian',$employee['kd_bagian']),(string)($values['infal']??0),4);
            $values['uovertime']=bcmul($rate('tunj_overtime','kd_bagian',$employee['kd_bagian']),(string)($values['overtime']??0),4);
            $weighted=bcadd((string)($values['hijin']??0),bcmul((string)($values['halpha']??0),'0.5',4),4);
            $values['absensi']=bcmul((string)($employee['t_harian']??0),$weighted,4);
            // Bindings proved from the EXE INSERT sequence and master-load handler.
            foreach(['p_anak'=>'p_anak','p_bjbs'=>'pinjaman_bsm','p_bank'=>'arisan','p_lunak'=>'pinjaman_lunak','p_lain'=>'lain1'] as $table=>$field)$values[$field]=$rate($table,'nip',$employee['nip']);
            $q=$db->prepare('SELECT jumlah,tarip FROM tunj_jam WHERE nip=?');$q->execute([$employee['nip']]);$jam=$q->fetch();$values['kelebihan']=$jam?bcmul((string)($jam['jumlah']??0),(string)($jam['tarip']??0),4):'0';
        }
        $values=$this->totals($values);
        foreach($values as $field=>$value){if($value!==null){$values[$field]=self::round((string)$value);if(strlen(ltrim($values[$field],'-'))>18)throw new InvalidArgumentException('Hasil perhitungan melebihi batas penyimpanan nominal.');}}
        return $values;
    }
}
