<?php
declare(strict_types=1);
namespace App\Domain;

/** Explicit source contracts. Archive-only tables must never become payroll defaults. */
final class LegacyMapping
{
    public const VERSION = 'legacy_mapping_v2';

    public static function tables(): array
    {
        $tables = [];
        $operation = static function (string $name, array $columns, array $key, array $dependencies = []) use (&$tables): void {
            $tables['dbo.'.$name] = [
                'target' => $name, 'classification' => 'operational', 'key' => $key,
                'columns' => array_combine($columns, array_map(static fn(string $column): string => $column === 'id' ? 'legacy_id' : $column, $columns)),
                'dependencies' => $dependencies, 'reason' => 'Master atau transaksi operasional aplikasi lama.',
            ];
        };
        $operation('bagian', ['kd_bagian', 'nama_bagian'], ['kd_bagian']);
        $operation('karyawan', ['id', 'nip', 'nama', 'kd_bagian', 'gapok', 't_jabatan', 't_harian', 't_lembur'], ['nip'], ['kd_bagian' => ['bagian', 'kd_bagian']]);
        foreach (['tunj_harian', 'tunj_konsumsi', 'tunj_lembur', 'tunj_infal', 'tunj_overtime'] as $name) {
            $operation($name, ['id', 'kd_bagian', 'jumlah'], ['kd_bagian'], ['kd_bagian' => ['bagian', 'kd_bagian']]);
        }
        $operation('tunj_jam', ['id', 'nip', 'jumlah', 'tarip'], ['nip'], ['nip' => ['karyawan', 'nip']]);
        foreach (['p_anak', 'p_bjbs', 'p_bank', 'p_lunak', 'p_lain', 'tabungan'] as $name) {
            $operation($name, ['id', 'nip', 'jumlah'], ['nip'], ['nip' => ['karyawan', 'nip']]);
        }
        $operation('penghasilan', PayrollFields::columns(), ['nip', 'bulan', 'tahun'], ['nip' => ['karyawan', 'nip']]);
        $tables['dbo.tunj_harian']['reason'] = 'Master tarif lama tetap dipertahankan; perhitungan transaksi memakai tarif harian pada karyawan.';
        $tables['dbo.tunj_lembur']['reason'] = 'Master tarif lama tetap dipertahankan; perhitungan transaksi memakai tarif lembur pada karyawan.';
        $tables['dbo.tabungan']['reason'] = 'Master lama dipertahankan; nilai tabungan transaksi tidak diisi ulang otomatis dari master ini.';
        $tables['dbo.pgw$'] = self::archive(['nip', 'nama', 'kd_bagian', 'gapok', 't_jabatan', 't_harian', 't_lembur'], 'reference', 'Referensi karyawan terpisah; identitas tidak otomatis disamakan dengan master karyawan aktif.');
        $tables['dbo.sheet1$'] = self::archive(['nama', 'nip', 'f3', 'kd_bagian'], 'reference', 'Referensi lembar kerja; bukan master aktif dan tidak digunakan sebagai tarif atau potongan.');
        $tables['dbo.orang'] = self::archive(['id_user', 'nama_user'], 'legacy_users', 'Identitas pengguna lama tanpa kredensial login aplikasi baru; dipertahankan sebagai arsip.');
        $tables['dbo.dtproperties'] = self::archive(['id', 'objectid', 'property', 'value', 'uvalue', 'lvalue', 'version'], 'sql_metadata', 'Metadata SQL Server; tidak memiliki fungsi penggajian operasional.');
        return $tables;
    }

    private static function archive(array $columns, string $classification, string $reason): array
    {
        return ['target' => null, 'classification' => $classification, 'key' => [], 'columns' => array_combine($columns, $columns), 'dependencies' => [], 'reason' => $reason];
    }
}
