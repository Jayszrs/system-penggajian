<?php
declare(strict_types=1);
use App\Domain\LegacyMapping;

function sourceFixture(): array {
    $tables = [];
    foreach (LegacyMapping::tables() as $source => $map) {
        [$schema, $name] = explode('.', $source, 2);
        $columns = [];
        $row = [];
        foreach (array_keys($map['columns']) as $field) {
            $length = match ($field) { 'nip' => 15, 'kd_bagian' => 5, 'nama' => 30, 'nama_bagian' => 20, 'bulan' => 2, 'tahun' => 4, 'id_user' => 10, 'nama_user' => 30, default => 0 };
            $text = $length > 0 || in_array($field, ['f3','property','value','uvalue','lvalue'], true);
            $columns[] = ['name' => $field, 'sql_type' => $text ? 'char' : 'numeric', 'max_length' => $text ? max(10, $length) : 9, 'precision' => $text ? 0 : 18, 'scale' => 0, 'is_nullable' => true];
            $row[$field] = match ($field) {
                'nip' => str_pad('00001', 15), 'kd_bagian' => 'Q0001', 'nama' => str_pad('Karyawan Uji Migrasi', 30), 'nama_bagian' => str_pad('Bagian Uji', 20), 'bulan' => '09', 'tahun' => '2025', 'id' => '7',
                'gapok' => '1000000', 't_jabatan' => '100000', 't_harian' => '10000', 't_lembur' => '20000',
                'jumlah' => '3000', 'tarip' => '2500', 'hefektif' => '21', 'hmasuk' => '21', 'hijin','halpha' => '0',
                'tpenghasilan' => '9876543', 'tpotongan' => '123', 'tbersih' => '9876420',
                'id_user' => 'OLDUSER', 'nama_user' => str_pad('Akun Lama Uji', 30), default => $text ? '' : '1',
            };
        }
        if ($map['target'] === null) $rows = $name === 'dtproperties' ? [] : [$row];
        else $rows = [$row];
        $tables[] = ['schema' => $schema, 'name' => $name, 'columns' => $columns, 'indexes' => [], 'foreign_keys' => [], 'row_count' => count($rows), 'rows' => $rows];
    }
    return ['source_sha256' => str_repeat('a', 64), 'source_database' => 'synthetic_legacy', 'source_file' => 'synthetic.dat', 'integrity' => 'RESTORE VERIFYONLY and DBCC CHECKDB passed', 'definitions' => [], 'backup_header' => [], 'backup_files' => [], 'tables' => $tables];
}
function changeTable(array &$export, string $name, callable $callback): void {
    foreach ($export['tables'] as &$table) if ($table['name'] === $name) { $callback($table); $table['row_count'] = count($table['rows']); }
}
