<?php
declare(strict_types=1);
namespace App\Domain;
final class PayrollFields {
    public const INCOME = ['gapok'=>'Gaji pokok','t_jabatan'=>'Tunjangan jabatan','t_harian'=>'Tunjangan harian','t_konsumsi'=>'Tunjangan konsumsi','ulembur'=>'Tunjangan lembur','uinfal'=>'Tunjangan infal','uovertime'=>'Tunjangan overtime','lain_lain'=>'Penghasilan lain-lain','kelebihan'=>'Kelebihan mengajar','lain1'=>'Tunjangan lain'];
    public const DEDUCTIONS = ['makan'=>'Makan siang','cash_bon'=>'Cash bon','pinjaman_lunak'=>'Pinjaman lunak','absensi'=>'Potongan absensi','tabungan'=>'Tabungan','arisan'=>'Pinjaman bank','pinjaman_bsm'=>'BJBS / BPJSKET','p_anak'=>'Pendidikan anak','plain'=>'Potongan lain-lain'];
    public const ATTENDANCE = ['hefektif'=>'Hari efektif','hmasuk'=>'Masuk kerja','hijin'=>'Izin / alpha','halpha'=>'Sakit','lembur'=>'Jumlah lembur','infal'=>'Jumlah infal','overtime'=>'Jumlah overtime'];
    public const TOTALS = ['tpenghasilan'=>'Total penghasilan','tpotongan'=>'Total potongan','tbersih'=>'Gaji bersih'];
    public static function numeric(): array { return array_merge(self::INCOME,self::DEDUCTIONS,self::ATTENDANCE,self::TOTALS); }
    public static function columns(): array { return array_merge(['nip','bulan','tahun'],array_keys(self::numeric())); }
}
