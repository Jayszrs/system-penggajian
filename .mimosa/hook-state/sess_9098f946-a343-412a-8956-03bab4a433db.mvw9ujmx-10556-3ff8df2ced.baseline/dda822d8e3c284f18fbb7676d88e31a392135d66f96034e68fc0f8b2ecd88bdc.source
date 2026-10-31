<?php
declare(strict_types=1);
namespace App\Domain;
final class MasterModules {
    public static function all(): array {
        $m = [
            'bagian'=>['title'=>'Data Bagian','singular'=>'bagian','group'=>'master','key'=>'kd_bagian','table'=>'bagian','fields'=>['kd_bagian'=>'Kode bagian','nama_bagian'=>'Nama bagian']],
            'karyawan'=>['title'=>'Data Karyawan','singular'=>'karyawan','group'=>'master','key'=>'nip','table'=>'karyawan','fields'=>['nip'=>'NIP','nama'=>'Nama lengkap','kd_bagian'=>'Bagian','gapok'=>'Gaji pokok','t_jabatan'=>'Tunjangan jabatan','t_harian'=>'Tarif harian','t_lembur'=>'Tarif lembur']],
        ];
        foreach (['harian'=>'Harian','konsumsi'=>'Konsumsi','lembur'=>'Lembur','infal'=>'Infal','overtime'=>'Overtime'] as $key=>$label) {
            $m['tunj_'.$key]=['title'=>'Tunjangan '.$label,'singular'=>'tarif tunjangan','group'=>'tunjangan','key'=>'kd_bagian','table'=>'tunj_'.$key,'fields'=>['kd_bagian'=>'Bagian','jumlah'=>'Nominal tarif']];
        }
        $m['tunj_jam']=['title'=>'Kelebihan Mengajar','singular'=>'kelebihan mengajar','group'=>'tunjangan','key'=>'nip','table'=>'tunj_jam','fields'=>['nip'=>'Karyawan','jumlah'=>'Kelebihan jam','tarip'=>'Tarif']];
        foreach (['anak'=>'Pendidikan Anak','bjbs'=>'BJBS / BPJSKET','bank'=>'Pinjaman Bank','lunak'=>'Pinjaman Lunak','lain'=>'Lain-lain'] as $key=>$label) {
            $m['p_'.$key]=['title'=>'Potongan '.$label,'singular'=>'potongan','group'=>'potongan','key'=>'nip','table'=>'p_'.$key,'fields'=>['nip'=>'Karyawan','jumlah'=>'Nominal potongan']];
        }
        return $m;
    }
    public static function get(string $key): array { return self::all()[$key] ?? throw new \InvalidArgumentException('Modul tidak ditemukan.'); }
    public static function monetary(string $field): bool { return in_array($field,['gapok','t_jabatan','t_harian','t_lembur','jumlah','tarip'],true); }
}
