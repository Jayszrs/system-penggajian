<?php
declare(strict_types=1);
namespace App\Domain;

final class PrintLayout {
    public const LOCATION = 'Tambun';
    public const INCOME = [
        'gapok'=>'Gaji Pokok', 't_jabatan'=>'T. Jabatan',
        't_harian'=>'T. Harian', 't_konsumsi'=>'T. Konsumsi',
        'ulembur'=>'Lembur', 'uinfal'=>'Infal', 'uovertime'=>'Overtime',
        'lain_lain'=>'Lain-lain', 'lain1'=>'Tunjangan Lain',
        'kelebihan'=>'Lebih Jam Mengajar',
    ];
    public const DEDUCTIONS = [
        'makan'=>'Makan Siang', 'cash_bon'=>'Cash Bon',
        'pinjaman_lunak'=>'Pinjaman Lunak', 'absensi'=>'Absensi',
        'tabungan'=>'Tabungan', 'arisan'=>'Pinjaman BANK',
        'pinjaman_bsm'=>'Potongan BPJSKET', 'p_anak'=>'Potongan Pend. Anak',
        'plain'=>'Potongan Lain-lain',
    ];

    public static function metadata(string $officer): array {
        return [
            'location'=>self::LOCATION,
            'officer'=>$officer,
            'timestamp'=>(new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('d-M-Y, H:i:s').' WIB',
        ];
    }

    public static function amount(mixed $value): string {
        return str_replace(['-Rp ', 'Rp '], ['-', ''], \money($value));
    }
}
