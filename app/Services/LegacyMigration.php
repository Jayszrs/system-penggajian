<?php
declare(strict_types=1);
namespace App\Services;

use App\Domain\LegacyMapping;
use PDO;
use RuntimeException;
use Throwable;

/** Lossless archive reconciliation and conservative, insert-only operational repair. */
final class LegacyMigration
{
    private array $issues = [];
    private array $exceptions = [];
    private bool $apply = false;
    private int $dataChanges = 0;
    private int $comparisons = 0;
    private array $targetColumns = [];

    public function __construct(private readonly PDO $db) {}

    public function run(array $export, bool $apply = true): array
    {
        $this->issues = $this->exceptions = $this->targetColumns = [];
        $this->dataChanges = $this->comparisons = 0;
        $this->apply = $apply;
        $tables = $this->validateExport($export);
        $hash = strtolower($export['source_sha256']);
        if ($this->db->inTransaction()) throw new RuntimeException('Migrasi harus memiliki transaksi database tersendiri.');
        $database = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') throw new RuntimeException('Database tujuan belum dipilih.');
        $lockName = 'legacy_migration:'.substr(hash('sha256', $database), 0, 40);
        $locked = false;
        try {
            if ($apply) {
                $statement = $this->db->prepare('SELECT GET_LOCK(?,10)'); $statement->execute([$lockName]);
                $locked = (int)$statement->fetchColumn() === 1;
                if (!$locked) throw new RuntimeException('Migrasi lain sedang memeriksa database tujuan.');
            } else $this->db->exec('SET TRANSACTION READ ONLY');
            $this->db->beginTransaction();
            $this->assertSource($hash);
            $archive = $this->reconcileArchive($tables);
            if ($apply) $archive = $this->reconcileArchive($tables); // Re-read newly archived bytes before any operational insert.
            $stateKey = 'legacy_migration_state:'.$hash;
            $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?'); $statement->execute([$stateKey]);
            $oldJson = $statement->fetchColumn();
            $oldState = $oldJson === false ? [] : json_decode((string)$oldJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($oldState)) throw new RuntimeException('Catatan rekonsiliasi lama tidak valid.');
            $ledger = []; $mappedRows = []; $tableDetails = [];
            foreach ($tables as $source => $table) {
                $map = LegacyMapping::tables()[$source] ?? null;
                $detail = ['table' => $table['schema'].'.'.$table['name'], 'rows' => count($table['rows']), 'columns' => count($table['columns']), 'sha256' => $table['multiset_hash'], 'archive_verified' => $archive[$source], 'operational' => $map !== null && $map['target'] !== null, 'classification' => $map['classification'] ?? 'unmapped', 'reason' => $map['reason'] ?? 'Tabel sumber belum memiliki keputusan pemetaan.', 'matched' => 0, 'inserted' => 0, 'missing' => 0, 'exceptions' => 0];
                if ($map === null) $this->issue('unknown_table', $source, null, 'Tabel sumber belum dipetakan; seluruh nilai tetap diarsipkan.');
                elseif ($map['target'] !== null) {
                    foreach ($table['prepared'] as $row) {
                        $result = $this->reconcileRow($source, $table, $map, $row, $oldState['rows'] ?? []);
                        $mappedRows[] = $result['summary'];
                        $ledger[$result['state_key']] = $result['state'];
                        $outcome = $result['summary']['outcome'];
                        if (isset($detail[$outcome])) $detail[$outcome]++;
                        else $detail['exceptions']++;
                    }
                }
                $tableDetails[] = $detail;
            }
            ksort($ledger);
            $rawRows = array_sum(array_column($tableDetails, 'rows'));
            $operationalRows = count(array_filter($mappedRows, static fn(array $row): bool => in_array($row['outcome'], ['matched', 'inserted'], true)));
            $totals = [];
            foreach (['tpenghasilan', 'tpotongan', 'tbersih'] as $field) $totals[$field] = (string)$this->db->query("SELECT COALESCE(SUM(`$field`),0) FROM penghasilan")->fetchColumn();
            $status = $this->issues ? 'needs_attention' : 'verified';
            $stableExceptions = [];
            foreach ($this->exceptions as $exception) {
                $stableException = $exception; unset($stableException['source_ordinal']);
                $raw = $exception['source_ordinal'] === null ? null : ($tables[$exception['table']]['rows'][$exception['source_ordinal'] - 1] ?? null);
                $stableException['source_row_hash'] = $raw === null ? null : hash('sha256', self::canonical($raw));
                $stableExceptions[] = $stableException;
            }
            $stable = ['mapping_version' => LegacyMapping::VERSION, 'source_sha256' => $hash, 'rows' => $ledger, 'status' => $status, 'exceptions' => $stableExceptions, 'source_tables' => array_map(static fn(array $table): array => ['columns' => $table['column_contract'], 'sha256' => $table['multiset_hash']], $tables)];
            usort($stable['exceptions'], static fn(array $a, array $b): int => strcmp(self::canonical($a), self::canonical($b)));
            $fingerprint = hash('sha256', self::canonical($stable));
            $metadataChanged = ($oldState['fingerprint'] ?? null) !== $fingerprint;
            $state = [...$stable, 'fingerprint' => $fingerprint];
            $summary = ['mapping_version' => LegacyMapping::VERSION, 'tables' => count($tables), 'rows' => $rawRows, 'operational_rows' => $operationalRows, 'value_comparisons' => $this->comparisons, 'issues' => $this->issues, 'exceptions' => $this->exceptions, 'totals' => $totals, 'integrity' => $export['integrity'], 'table_details' => $tableDetails, 'mapped_rows' => $mappedRows, 'metadata_changed' => $metadataChanged, 'data_changes' => $this->dataChanges, 'fingerprint' => $fingerprint, 'status' => $status, 'dry_run' => !$apply];
            if ($apply && $metadataChanged) {
                $this->db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$stateKey, self::canonical($state)]);
                $this->db->prepare('INSERT INTO migration_runs(source_sha256,source_database,status,summary) VALUES(?,?,?,?)')->execute([$hash, $export['source_database'], $status, self::canonical($summary)]);
                $this->initialSetting('legacy_definitions', $export['definitions'] ?? []);
                $this->initialSetting('legacy_backup_metadata', ['header' => $export['backup_header'] ?? [], 'files' => $export['backup_files'] ?? []]);
            }
            $apply ? $this->db->commit() : $this->db->rollBack();
            return $summary;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        } finally {
            if ($locked) { $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lockName]); }
        }
    }

    private function validateExport(array $export): array
    {
        if (($export['integrity'] ?? '') !== 'RESTORE VERIFYONLY and DBCC CHECKDB passed') throw new RuntimeException('Ekspor belum lolos pemeriksaan integritas SQL Server.');
        if (!is_string($export['source_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/iD', $export['source_sha256'])) throw new RuntimeException('SHA-256 sumber tidak valid.');
        if (!is_string($export['source_database'] ?? null) || $export['source_database'] === '' || strlen($export['source_database']) > 128) throw new RuntimeException('Identitas database sumber tidak valid.');
        if (!is_array($export['tables'] ?? null) || !array_is_list($export['tables']) || !$export['tables']) throw new RuntimeException('Inventaris tabel sumber tidak tersedia.');
        if (array_key_exists('format_version', $export)) {
            if ($export['format_version'] !== 2) throw new RuntimeException('Versi format ekspor tidak didukung.');
            $provenance = $export['provenance'] ?? null;
            if (!is_array($provenance)) throw new RuntimeException('Bukti pemulihan ekspor versi 2 tidak tersedia.');
            foreach (['restore_verifyonly', 'dbcc_checkdb', 'audit_read_only'] as $check) {
                if (($provenance[$check] ?? null) !== true) throw new RuntimeException('Bukti pemulihan ekspor versi 2 belum lengkap: '.$check.'.');
            }
            foreach (['source_sha256', 'mounted_sha256'] as $field) {
                if (!is_string($provenance[$field] ?? null) || !hash_equals(strtolower($export['source_sha256']), strtolower($provenance[$field]))) throw new RuntimeException('Identitas backup pada bukti pemulihan tidak cocok: '.$field.'.');
            }
            if (array_key_exists('source_table_count', $provenance) && (!is_int($provenance['source_table_count']) || $provenance['source_table_count'] !== count($export['tables']))) throw new RuntimeException('Jumlah tabel pada bukti pemulihan berbeda dari inventaris ekspor.');
        }
        $tables = [];
        foreach ($export['tables'] as $table) {
            if (!is_array($table) || !is_string($table['schema'] ?? null) || !is_string($table['name'] ?? null) || $table['schema'] === '' || $table['name'] === '' || strlen($table['schema'].'.'.$table['name']) > 256) throw new RuntimeException('Identitas tabel sumber tidak valid.');
            $source = strtolower($table['schema'].'.'.$table['name']);
            if (isset($tables[$source])) throw new RuntimeException('Tabel sumber memiliki identitas ganda.');
            if (!is_array($table['columns'] ?? null) || !array_is_list($table['columns']) || !$table['columns'] || !is_array($table['rows'] ?? null) || !array_is_list($table['rows'])) throw new RuntimeException('Struktur tabel sumber tidak valid: '.$source);
            if (!is_scalar($table['row_count'] ?? null) || !preg_match('/^\d+$/D', (string)$table['row_count']) || (int)$table['row_count'] !== count($table['rows'])) throw new RuntimeException('Jumlah baris sumber tidak cocok: '.$source);
            $columns = []; $contract = [];
            foreach ($table['columns'] as $column) {
                if (!is_array($column) || !is_string($column['name'] ?? null) || !is_string($column['sql_type'] ?? null) || $column['name'] === '') throw new RuntimeException('Metadata kolom tidak valid: '.$source);
                $field = strtolower($column['name']); $type = strtolower($column['sql_type']);
                if (isset($columns[$field])) throw new RuntimeException('Nama kolom sumber ambigu: '.$source);
                if (!preg_match('/^[a-z][a-z0-9_]*$/D', $type)) throw new RuntimeException('Tipe kolom sumber tidak valid: '.$source);
                if (in_array($type, ['numeric', 'decimal'], true) && (!isset($column['precision'], $column['scale']) || !ctype_digit((string)$column['precision']) || !ctype_digit((string)$column['scale']) || (int)$column['precision'] < 1 || (int)$column['precision'] > 38 || (int)$column['scale'] > (int)$column['precision'])) throw new RuntimeException('Presisi numerik sumber tidak valid: '.$source);
                $column['sql_type'] = $type; $columns[$field] = $column;
                $contract[$field] = ['sql_type' => $type, 'precision' => (int)($column['precision'] ?? 0), 'scale' => (int)($column['scale'] ?? 0), 'max_length' => (int)($column['max_length'] ?? 0)];
            }
            ksort($contract); $digests = [];
            foreach ($table['rows'] as $row) {
                if (!is_array($row) || count($row) !== count($columns)) throw new RuntimeException('Jumlah kolom pada baris sumber tidak cocok: '.$source);
                foreach ($columns as $column) {
                    if (!array_key_exists($column['name'], $row)) throw new RuntimeException('Kolom baris sumber tidak lengkap: '.$source);
                    $value = $row[$column['name']];
                    if ($value !== null && !is_string($value) && !is_int($value) && !(is_array($value) && array_keys($value) === ['__binary'] && is_string($value['__binary']))) throw new RuntimeException('Nilai sumber harus berupa string/null atau binary lossless: '.$source);
                    if ($value !== null && in_array($column['sql_type'], ['numeric', 'decimal', 'int', 'smallint', 'tinyint', 'bigint', 'bit', 'money', 'smallmoney'], true) && (!is_scalar($value) || !preg_match('/^-?\d+(?:\.\d+)?$/D', (string)$value))) throw new RuntimeException('Nilai numerik sumber tidak valid: '.$source);
                }
                $digests[] = hash('sha256', self::canonical($row));
            }
            sort($digests, SORT_STRING);
            $table['column_map'] = $columns; $table['column_contract'] = $contract; $table['multiset_hash'] = hash('sha256', implode("\n", $digests));
            $table['prepared'] = [];
            $map = LegacyMapping::tables()[$source] ?? null;
            if ($map !== null) {
                foreach ($columns as $field => $column) if (!array_key_exists($field, $map['columns'])) $this->issue('unknown_column', $source, null, 'Kolom sumber belum dipetakan: '.$field.'. Nilai asli tetap diarsipkan.');
                if ($map['target'] !== null) {
                    $missing = array_diff(array_keys($map['columns']), array_keys($columns), ['id']);
                    $invalidTypes = [];
                    foreach ($columns as $field => $column) {
                        if (!isset($map['columns'][$field])) continue;
                        $numeric = !in_array($field, ['nip', 'nama', 'kd_bagian', 'nama_bagian', 'bulan', 'tahun'], true);
                        $allowed = $numeric ? ['numeric', 'decimal', 'int', 'smallint', 'tinyint', 'bigint', 'money', 'smallmoney'] : ['char', 'nchar', 'varchar', 'nvarchar'];
                        if (!in_array($column['sql_type'], $allowed, true)) {
                            $invalidTypes[] = $field;
                            $this->issue('incompatible_source_type', $source, null, 'Tipe kolom sumber tidak sesuai dengan komponen operasional: '.$field.'.');
                        }
                    }
                    foreach ($missing as $field) $this->issue('missing_column', $source, null, 'Kolom operasional sumber tidak tersedia: '.$field.'.');
                    $identities = []; $naturalKeys = [];
                    foreach ($table['rows'] as $ordinal => $raw) {
                        $mapped = []; $valid = !$missing && !$invalidTypes;
                        foreach ($columns as $field => $column) {
                            if (!isset($map['columns'][$field])) continue;
                            $targetField = $map['columns'][$field];
                            try { $mapped[$targetField] = $this->operationalValue($raw[$column['name']], $column); }
                            catch (RuntimeException) { $valid = false; $this->issue('invalid_value', $source, $ordinal + 1, 'Nilai operasional tidak dapat dipetakan dengan tepat: '.$field.'.'); }
                        }
                        try { $naturalKey = $this->naturalKey($map, $mapped); }
                        catch (RuntimeException) { $naturalKey = null; $valid = false; $this->issue('invalid_key', $source, $ordinal + 1, 'Identitas/NIP/periode sumber tidak valid.'); }
                        $sourceIdentity = isset($mapped['legacy_id']) ? 'id:'.$mapped['legacy_id'] : 'key:'.($naturalKey ?? 'invalid:'.hash('sha256', self::canonical($raw)));
                        $identity = hash('sha256', $sourceIdentity);
                        $table['prepared'][] = ['ordinal' => $ordinal + 1, 'mapped' => $mapped, 'natural_key' => $naturalKey, 'identity' => $identity, 'valid' => $valid];
                        $identities[$identity][] = $ordinal;
                        if ($naturalKey !== null) $naturalKeys[$naturalKey][] = $ordinal;
                    }
                    foreach ([['source_identity_duplicate', $identities], ['source_key_duplicate', $naturalKeys]] as [$code, $groups]) {
                        foreach ($groups as $ordinals) if (count($ordinals) > 1) foreach ($ordinals as $ordinal) { $table['prepared'][$ordinal]['valid'] = false; $this->issue($code, $source, $ordinal + 1, 'Identitas sumber tidak unik; baris operasional tidak dipilih secara otomatis.'); }
                    }
                    $occurrences = [];
                    foreach ($table['prepared'] as &$prepared) {
                        $prepared['ledger_identity'] = $prepared['identity'];
                        if (count($identities[$prepared['identity']]) > 1) {
                            $digest = hash('sha256', self::canonical($table['rows'][$prepared['ordinal'] - 1]));
                            $group = $prepared['identity'].':duplicate:'.$digest;
                            $occurrences[$group] = ($occurrences[$group] ?? 0) + 1;
                            $prepared['ledger_identity'] = $group.':'.$occurrences[$group];
                        }
                    }
                    unset($prepared);
                    usort($table['prepared'], static fn(array $a, array $b): int => strcmp($a['ledger_identity'], $b['ledger_identity']));
                }
            }
            $tables[$source] = $table;
        }
        foreach (LegacyMapping::tables() as $source => $map) {
            if ($map['target'] !== null && !isset($tables[$source])) $this->issue('missing_source_table', $source, null, 'Tabel operasional tidak tersedia pada inventaris sumber; kelengkapan migrasi belum dapat diverifikasi.');
        }
        $order = array_flip(array_keys(LegacyMapping::tables()));
        uksort($tables, static fn(string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));
        // A local-only parent must not hide an orphan in the supplied backup.
        foreach ($tables as $source => &$table) {
            $map = LegacyMapping::tables()[$source] ?? null;
            if ($map === null || $map['target'] === null) continue;
            foreach ($table['prepared'] as &$row) {
                if (!$row['valid']) continue;
                foreach ($map['dependencies'] as $field => [$parentTable, $parentField]) {
                    $parents = $tables['dbo.'.$parentTable]['prepared'] ?? [];
                    $matches = array_filter($parents, static fn(array $parent): bool => $parent['valid'] && ($parent['mapped'][$parentField] ?? null) !== null && $parent['mapped'][$parentField] === ($row['mapped'][$field] ?? null));
                    if (count($matches) !== 1) { $row['valid'] = false; $this->issue('source_orphan_reference', $source, $row['ordinal'], 'Relasi pada backup sumber ke '.$parentTable.' tidak ditemukan atau tidak unik.'); }
                }
            }
            unset($row);
        }
        unset($table);
        // Dependency order is independent of source export order.
        return $tables;
    }

    private function assertSource(string $hash): void
    {
        $hashes = $this->db->query('SELECT DISTINCT source_sha256 FROM migration_runs')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($hashes as $existing) if (!hash_equals(strtolower((string)$existing), $hash)) throw new RuntimeException('Database tujuan memiliki riwayat backup berbeda. Gunakan database terpisah.');
        $archiveCount = (int)$this->db->query('SELECT COUNT(*) FROM legacy_tables')->fetchColumn();
        if ($archiveCount && !$hashes) {
            $settings = $this->db->query("SELECT setting_key FROM app_settings WHERE setting_key LIKE 'legacy_migration_state:%'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('legacy_migration_state:'.$hash, $settings, true)) throw new RuntimeException('Arsip tujuan tidak memiliki bukti identitas backup. Audit manual diperlukan.');
        }
    }

    private function reconcileArchive(array $tables): array
    {
        $catalog = $this->db->query('SELECT * FROM legacy_tables')->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($catalog as $row) {
            $source = strtolower($row['table_name']);
            if (isset($existing[$source])) throw new RuntimeException('Identitas tabel arsip ambigu.');
            $existing[$source] = $row;
        }
        $initialized = (bool)$existing;
        if ($initialized && array_diff(array_keys($existing), array_keys($tables))) throw new RuntimeException('Inventaris ekspor tidak mencakup seluruh tabel arsip lama.');
        if (!$initialized && (int)$this->db->query('SELECT COUNT(*) FROM legacy_records')->fetchColumn()) throw new RuntimeException('Arsip memiliki baris tanpa katalog; pemulihan otomatis dibatalkan.');
        $verified = [];
        foreach ($tables as $source => $table) {
            $name = $table['schema'].'.'.$table['name'];
            if (!isset($existing[$source])) {
                if ($initialized) throw new RuntimeException('Ekspor memiliki tabel yang tidak tercatat pada arsip lama: '.$source);
                if ($this->apply) {
                    $statement = $this->db->prepare('INSERT INTO legacy_records(table_name,source_ordinal,payload,row_sha256) VALUES(?,?,?,?)'); $ordered = [];
                    foreach ($table['rows'] as $ordinal => $row) { $payload = self::canonical($row); $digest = hash('sha256', $payload); $statement->execute([$name, $ordinal + 1, $payload, $digest]); $ordered[] = $digest; }
                    $metadata = $table; unset($metadata['rows'], $metadata['prepared'], $metadata['column_map'], $metadata['column_contract'], $metadata['multiset_hash']);
                    $this->db->prepare('INSERT INTO legacy_tables(table_name,row_count,schema_data,content_sha256) VALUES(?,?,?,?)')->execute([$name, count($table['rows']), self::canonical($metadata), hash('sha256', implode("\n", $ordered))]);
                }
                else $this->issue('archive_missing', $source, null, 'Arsip sumber belum disimpan pada database tujuan.');
                $verified[$source] = $this->apply;
                continue;
            }
            $old = $existing[$source];
            $metadata = json_decode($old['schema_data'], true, 512, JSON_THROW_ON_ERROR);
            $oldColumns = [];
            foreach ($metadata['columns'] ?? [] as $column) $oldColumns[strtolower($column['name'])] = ['sql_type' => strtolower($column['sql_type']), 'precision' => (int)($column['precision'] ?? 0), 'scale' => (int)($column['scale'] ?? 0), 'max_length' => (int)($column['max_length'] ?? 0)];
            ksort($oldColumns);
            if ($oldColumns !== $table['column_contract']) throw new RuntimeException('Struktur sumber berbeda dari arsip terverifikasi: '.$source);
            $statement = $this->db->prepare('SELECT payload,row_sha256 FROM legacy_records WHERE table_name=? ORDER BY source_ordinal'); $statement->execute([$old['table_name']]);
            $ordered = []; $canonical = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $digest = hash('sha256', $row['payload']);
                if (!hash_equals($row['row_sha256'], $digest)) throw new RuntimeException('Checksum baris arsip rusak: '.$source);
                $decoded = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) throw new RuntimeException('Payload arsip tidak valid: '.$source);
                $ordered[] = $digest; $canonical[] = hash('sha256', self::canonical($decoded));
            }
            if (count($ordered) !== (int)$old['row_count'] || count($ordered) !== count($table['rows'])) throw new RuntimeException('Jumlah baris arsip berbeda: '.$source);
            sort($canonical, SORT_STRING);
            if (!hash_equals($old['content_sha256'], hash('sha256', implode("\n", $ordered))) && !hash_equals($old['content_sha256'], hash('sha256', implode("\n", $canonical)))) throw new RuntimeException('Checksum tabel arsip rusak: '.$source);
            if (!hash_equals($table['multiset_hash'], hash('sha256', implode("\n", $canonical)))) throw new RuntimeException('Nilai arsip berbeda dari backup sumber: '.$source);
            $verified[$source] = true;
        }
        return $verified;
    }

    private function reconcileRow(string $source, array $table, array $map, array $row, array $oldRows): array
    {
        $stateKey = $source.':'.($row['ledger_identity'] ?? $row['identity']); $mapped = $row['mapped'];
        $sourceHash = hash('sha256', self::canonical($mapped));
        $old = $oldRows[$stateKey] ?? [];
        $state = ['target_id' => $old['target_id'] ?? null, 'source_hash' => $sourceHash, 'target_baseline' => $old['target_baseline'] ?? null, 'target_hash' => null, 'outcome' => 'exception'];
        $summary = ['table' => $source, 'source_identity' => $row['identity'], 'target_table' => $map['target'], 'target_id' => null, 'outcome' => 'exception'];
        $result = static function () use ($stateKey, &$state, &$summary): array { return ['state_key' => $stateKey, 'state' => $state, 'summary' => $summary]; };
        if (!$row['valid']) return $result();
        $available = $this->columns($map['target']);
        foreach (array_keys($mapped) as $field) if (!isset($available[$field])) { $this->issue('target_column_missing', $source, $row['ordinal'], 'Kolom tujuan belum tersedia: '.$field.'. Jalankan penyiapan skema terlebih dahulu.'); return $result(); }
        foreach ($mapped as $field => $value) {
            try { $mapped[$field] = $this->destinationValue($value, $available[$field]); }
            catch (RuntimeException) { $this->issue('target_value_incompatible', $source, $row['ordinal'], 'Nilai sumber tidak dapat disimpan utuh pada kolom tujuan: '.$field.'.'); return $result(); }
        }
        $sourceHash = hash('sha256', self::canonical($mapped)); $state['source_hash'] = $sourceHash;
        foreach ($map['dependencies'] as $field => [$parentTable, $parentField]) {
            $statement = $this->db->prepare("SELECT id FROM `$parentTable` WHERE `$parentField`=?".($this->apply ? ' FOR SHARE' : '')); $statement->execute([$mapped[$field] ?? null]);
            if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== 1) { $this->issue('orphan_reference', $source, $row['ordinal'], 'Relasi sumber ke '.$parentTable.' tidak ditemukan atau tidak unik.'); return $result(); }
        }
        $target = $this->findTarget($map, $mapped, $old, $source, $row['ordinal']);
        if ($target === null) return $result(); // Ambiguous identity, already reported.
        if ($target === false) {
            if (isset($old['target_id']) || $this->wasDeleted($map, $mapped)) {
                $this->issue('preserved_deletion', $source, $row['ordinal'], 'Penghapusan lokal dipertahankan; baris sumber tidak dimasukkan ulang.');
                $state['outcome'] = $summary['outcome'] = 'preserved_deletion'; return $result();
            }
            if (!$this->apply) { $summary['outcome'] = $state['outcome'] = 'missing'; $this->issue('target_row_missing', $source, $row['ordinal'], 'Baris sumber belum tersedia pada tujuan; jalankan migrasi untuk memasukkan baris yang aman.'); return $result(); }
            $insert = $mapped;
            if ($map['target'] === 'penghasilan') {
                $statement = $this->db->prepare('SELECT k.nama,k.kd_bagian,b.nama_bagian FROM karyawan k LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian WHERE k.nip=?'); $statement->execute([$mapped['nip']]); $employee = $statement->fetch(PDO::FETCH_ASSOC);
                $insert += ['source' => 'legacy', 'employee_name' => $employee['nama'] ?? null, 'department_code' => $employee['kd_bagian'] ?? null, 'department_name' => $employee['nama_bagian'] ?? null];
            }
            $fields = implode('`,`', array_keys($insert)); $placeholders = implode(',', array_fill(0, count($insert), '?'));
            $this->db->prepare("INSERT INTO `{$map['target']}` (`$fields`) VALUES($placeholders)")->execute(array_values($insert));
            $id = (string)$this->db->lastInsertId(); $statement = $this->db->prepare("SELECT * FROM `{$map['target']}` WHERE id=?"); $statement->execute([$id]); $target = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$target || !$this->sameFields($mapped, $target)) throw new RuntimeException('Verifikasi nilai setelah memasukkan data tidak cocok: '.$source);
            $this->dataChanges++; $summary['outcome'] = 'inserted';
        } else {
            $state['target_hash'] = hash('sha256', self::canonical(array_intersect_key($target, $mapped)));
            if (!$this->sameFields($mapped, $target)) {
                $state['target_id'] = $summary['target_id'] = (string)$target['id'];
                $state['outcome'] = $summary['outcome'] = 'preserved_local';
                $this->issue('preserved_local_difference', $source, $row['ordinal'], 'Nilai lokal berbeda dari sumber dan dipertahankan; periksa sebelum koreksi manual.'); return $result();
            }
            $summary['outcome'] = 'matched';
        }
        $state['target_id'] = $summary['target_id'] = (string)$target['id'];
        $state['target_baseline'] = $state['target_hash'] = $sourceHash; $state['outcome'] = 'matched';
        return $result();
    }

    /** null means unsafe ambiguity; false means no matching destination row. */
    private function findTarget(array $map, array $mapped, array $old, string $source, int $ordinal): array|false|null
    {
        $table = $map['target']; $candidates = [];
        if (isset($old['target_id'])) {
            $statement = $this->db->prepare("SELECT * FROM `$table` WHERE id=?".($this->apply ? ' FOR UPDATE' : '')); $statement->execute([$old['target_id']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            $candidates[(string)$row['id']] = $row;
        }
        if (isset($mapped['legacy_id'])) {
            $statement = $this->db->prepare("SELECT * FROM `$table` WHERE legacy_id=?".($this->apply ? ' FOR UPDATE' : '')); $statement->execute([$mapped['legacy_id']]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $candidates[(string)$row['id']] = $row;
        }
        $where = []; $params = [];
        foreach ($map['key'] as $field) {
            if ($table === 'penghasilan' && in_array($field, ['bulan', 'tahun'], true)) { $where[] = "`$field` REGEXP '^[0-9]+$' AND CAST(`$field` AS UNSIGNED)=?"; $params[] = (int)$mapped[$field]; }
            else { $where[] = "`$field`=?"; $params[] = $mapped[$field]; }
        }
        $statement = $this->db->prepare("SELECT * FROM `$table` WHERE ".implode(' AND ', $where).($this->apply ? ' FOR UPDATE' : '')); $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $candidates[(string)$row['id']] = $row;
        if (count($candidates) > 1) { $this->issue('target_identity_conflict', $source, $ordinal, 'Identitas sumber mengarah ke beberapa baris tujuan; tidak ada perubahan otomatis.'); return null; }
        return $candidates ? reset($candidates) : false;
    }

    private function wasDeleted(array $map, array $mapped): bool
    {
        $statement = $this->db->prepare("SELECT before_data FROM audit_log WHERE action='delete' AND entity=?"); $statement->execute([$map['target']]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            if (!$json) continue;
            $before = json_decode($json, true);
            if (!is_array($before)) continue;
            if (isset($mapped['legacy_id'], $before['legacy_id']) && (string)$mapped['legacy_id'] === (string)$before['legacy_id']) return true;
            try { if ($this->naturalKey($map, $before) === $this->naturalKey($map, $mapped)) return true; }
            catch (RuntimeException) { /* An unrelated legacy audit entry may not contain all keys. */ }
        }
        return false;
    }

    private function sameFields(array $mapped, array $target): bool
    {
        $same = true;
        foreach ($mapped as $field => $value) {
            $this->comparisons++;
            if (!array_key_exists($field, $target) || ($value === null ? $target[$field] !== null : $target[$field] === null || (string)$target[$field] !== (string)$value)) $same = false;
        }
        return $same;
    }

    private function naturalKey(array $map, array $row): string
    {
        $parts = [];
        foreach ($map['key'] as $field) {
            $value = $row[$field] ?? null;
            if (!is_string($value) && !is_int($value)) throw new RuntimeException('Invalid natural key.');
            $value = rtrim((string)$value, ' ');
            if ($value === '' || str_contains($value, "\0")) throw new RuntimeException('Invalid natural key.');
            if ($field === 'nip' && mb_strlen($value) > 15 || $field === 'kd_bagian' && mb_strlen($value) > 5) throw new RuntimeException('Natural key too long.');
            if ($map['target'] === 'penghasilan' && in_array($field, ['bulan', 'tahun'], true)) {
                if (!ctype_digit($value) || $field === 'bulan' && ((int)$value < 1 || (int)$value > 12) || $field === 'tahun' && ((int)$value < 1900 || (int)$value > 9999)) throw new RuntimeException('Invalid payroll period.');
                $value = (string)(int)$value;
            }
            $parts[$field] = $value;
        }
        return self::canonical($parts);
    }

    private function operationalValue(mixed $value, array $column): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('Non-scalar operational value.');
        $value = (string)$value;
        if (in_array($column['sql_type'], ['char', 'nchar'], true)) return rtrim($value, ' ');
        if (in_array($column['sql_type'], ['numeric', 'decimal'], true)) {
            $scale = (int)$column['scale'];
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) throw new RuntimeException('Invalid decimal.');
            $canonical = bcadd($value, '0', $scale);
            if (bccomp($value, $canonical, max($scale, strlen(explode('.', $value, 2)[1] ?? ''))) !== 0) throw new RuntimeException('Lossy decimal.');
            return $canonical;
        }
        return $value;
    }

    private function columns(string $table): array
    {
        if (!isset($this->targetColumns[$table])) {
            $statement = $this->db->query("SHOW COLUMNS FROM `$table`");
            $this->targetColumns[$table] = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) $this->targetColumns[$table][$column['Field']] = $column;
        }
        return $this->targetColumns[$table];
    }

    /** Normalize only when the destination representation retains the entire source value. */
    private function destinationValue(?string $value, array $column): ?string
    {
        if ($value === null) {
            if ($column['Null'] !== 'YES') throw new RuntimeException('Destination rejects NULL.');
            return null;
        }
        $type = strtolower($column['Type']);
        if (preg_match('/^(?:decimal|numeric)\((\d+),(\d+)\)$/D', $type, $match)) {
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) throw new RuntimeException('Destination rejects number.');
            $precision = (int)$match[1]; $scale = (int)$match[2];
            $normalized = bcadd($value, '0', $scale);
            $sourceScale = strlen(explode('.', $value, 2)[1] ?? '');
            if (bccomp($value, $normalized, max($scale, $sourceScale)) !== 0) throw new RuntimeException('Destination would lose decimal places.');
            $integer = ltrim(explode('.', ltrim($normalized, '-'), 2)[0], '0');
            if (strlen($integer) > $precision - $scale) throw new RuntimeException('Destination precision exceeded.');
            return $normalized;
        }
        if (preg_match('/^(?:var)?char\((\d+)\)$/D', $type, $match)) {
            if (mb_strlen($value) > (int)$match[1]) throw new RuntimeException('Destination text would be truncated.');
            return $value;
        }
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?( unsigned)?$/D', $type, $match)) {
            if (!preg_match('/^-?\d+$/D', $value)) throw new RuntimeException('Destination integer required.');
            if (str_contains($type, 'unsigned') && bccomp($value, '0', 0) < 0) throw new RuntimeException('Destination unsigned integer required.');
            return bcadd($value, '0', 0);
        }
        throw new RuntimeException('Unexpected destination type.');
    }

    private function initialSetting(string $key, mixed $value): void
    {
        $statement = $this->db->prepare('SELECT 1 FROM app_settings WHERE setting_key=?'); $statement->execute([$key]);
        if (!$statement->fetchColumn()) $this->db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')->execute([$key, self::canonical($value)]);
    }

    private function issue(string $code, string $source, ?int $ordinal, string $message): void
    {
        $exception = ['code' => $code, 'table' => $source, 'source_ordinal' => $ordinal, 'message' => $message];
        $this->exceptions[] = $exception;
        $this->issues[] = $source.($ordinal === null ? '' : ' baris '.$ordinal).': '.$message;
    }

    public static function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
