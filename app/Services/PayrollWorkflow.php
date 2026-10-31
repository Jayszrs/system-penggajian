<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Domain\{PayrollFields, PayrollPeriod, PayrollValidationException};
use Throwable;

/** Keeps preview, acknowledgement, and persistence on the same calculation path. */
final class PayrollWorkflow
{
    public static function id(mixed $raw): int
    {
        if ((!is_string($raw) && !is_int($raw)) || !preg_match('/^\d{1,19}$/D', (string)$raw)
            || bccomp((string)$raw, (string)PHP_INT_MAX, 0) > 0) {
            throw new PayrollValidationException('Identitas transaksi tidak valid.', 'id', 'invalid_transaction_id');
        }
        return (int)$raw;
    }

    public function preview(array $input): array
    {
        $request = $this->request($input);
        $db = Database::connection();
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        try {
            [$before, $employee] = $this->records($request, false);
            $result = (new PayrollCalculator())->evaluate($input, $request['mode'] === 'master', ['before' => $before, 'month' => $request['bulan'], 'year' => $request['tahun']]);
            $identity = $this->identity($request, $before);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        return $this->confirmation($result, $identity, $input);
    }

    /** @return array{saved:int|false,calculation:array} */
    public function save(array $input): array
    {
        $request = $this->request($input);
        $db = Database::connection();
        $lock = 'mh-payroll-'.substr(hash('sha256', ($_ENV['DB_DATABASE'] ?? 'penggajian').'|'.$request['nip'].'|'.$request['tahun'].'|'.$request['bulan']), 0, 50);
        $locked = false;
        try {
            $statement = $db->prepare('SELECT GET_LOCK(?,5)'); $statement->execute([$lock]);
            if (!(int)$statement->fetchColumn()) throw new PayrollValidationException('Transaksi sedang diproses. Silakan coba kembali.', 'nip', 'transaction_busy');
            $locked = true;
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction();
            [$before, $employee] = $this->records($request, true);
            $statement = $db->prepare('SELECT id FROM penghasilan WHERE nip=? AND CAST(bulan AS UNSIGNED)=? AND CAST(tahun AS UNSIGNED)=? AND id<>? FOR UPDATE');
            $statement->execute([$request['nip'], $request['bulan'], $request['tahun'], $request['id']]);
            if ($statement->fetchColumn()) throw new PayrollValidationException('NIP dan periode ini sudah memiliki transaksi. Buka transaksi tersimpan untuk mengubahnya.', 'nip', 'duplicate_period');
            $result = (new PayrollCalculator())->evaluate($input, $request['mode'] === 'master', ['before' => $before, 'month' => $request['bulan'], 'year' => $request['tahun']]);
            $identity = $this->identity($request, $before);
            [$token, $accepted, $valid] = $this->tokenState($input, $result, $identity);
            $requiresAcknowledgement = $result['issues'] !== [] || ($valid && PayrollConfirmation::requiresAcknowledgement($token));
            if (($token !== '' && !$valid) || ($requiresAcknowledgement && !($valid && $accepted))) {
                $db->rollBack();
                return ['saved' => false, 'calculation' => $this->confirmation($result, $identity, $input)];
            }
            $snapshot = json_encode($result['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($before !== null && $request['mode'] === 'manual'
                && $result['calculation_version'] === 'legacy_v1' && $result['issues'] === []
                && ($before['calculation_snapshot'] ?? null) === null) $snapshot = null;
            $record = [
                'nip' => $request['nip'],
                'bulan' => $before !== null ? $before['bulan'] : str_pad((string)$request['bulan'], 2, '0', STR_PAD_LEFT),
                'tahun' => $before !== null ? $before['tahun'] : (string)$request['tahun'],
                ...$result['values'],
                'source' => $before !== null ? $before['source'] : ($request['mode'] === 'master' ? 'calculated' : 'manual'),
                'employee_name' => $before !== null ? $before['employee_name'] : $employee['nama'],
                'department_code' => $before !== null ? $before['department_code'] : $employee['kd_bagian'],
                'department_name' => $before !== null ? $before['department_name'] : $employee['nama_bagian'],
                'calculation_version' => $result['calculation_version'], 'calculation_snapshot' => $snapshot,
            ];
            $id = $request['id'];
            if ($id) {
                $statement = $db->prepare('UPDATE penghasilan SET '.implode(',', array_map(static fn($field) => "`$field`=?", array_keys($record))).' WHERE id=?');
                $statement->execute([...array_values($record), $id]);
            } else {
                $statement = $db->prepare('INSERT INTO penghasilan (`'.implode('`,`', array_keys($record)).'`) VALUES('.implode(',', array_fill(0, count($record), '?')).')');
                $statement->execute(array_values($record)); $id = (int)$db->lastInsertId();
            }
            $after = [...$record, '_calculation' => ['version' => $result['calculation_version'], 'snapshot' => $result['snapshot'], 'issues' => $result['issues'], 'acknowledged' => $valid && $accepted, 'before_revision' => $identity['before_revision']]];
            $statement = $db->prepare('INSERT INTO audit_log(user_id,action,entity,record_key,before_data,after_data) VALUES(?,?,?,?,?,?)');
            $statement->execute([$_SESSION['user']['id'] ?? null, $before === null ? 'create' : 'update', 'penghasilan', (string)$id,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $db->commit();
            if ($token !== '') PayrollConfirmation::consume($token);
            return ['saved' => $id, 'calculation' => [...$result, 'acknowledged' => $valid && $accepted]];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        } finally {
            if ($locked) {
                $statement = $db->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lock]);
            }
        }
    }

    private function request(array $input): array
    {
        $id = self::id($input['id'] ?? '0');
        $nip = $this->text($input['nip'] ?? '', 'nip', 'NIP');
        if ($nip === '' || strlen($nip) > 15) throw new PayrollValidationException('Pilih NIP karyawan yang valid, maksimal 15 karakter.', 'nip', 'invalid_employee');
        $month = PayrollPeriod::value($input['bulan'] ?? null, 'Bulan', 1, 12);
        $year = PayrollPeriod::value($input['tahun'] ?? null, 'Tahun', 1900, 9999);
        $department = $this->text($input['bagian'] ?? '', 'bagian', 'Bagian');
        if (strlen($department) > 5) throw new PayrollValidationException('Kode bagian maksimal 5 karakter.', 'bagian', 'invalid_department');
        $mode = $this->text($input['calculation_mode'] ?? 'manual', 'calculation_mode', 'Mode perhitungan');
        if (!in_array($mode, ['manual', 'master'], true)) throw new PayrollValidationException('Pilih mode perhitungan yang tersedia.', 'calculation_mode', 'invalid_calculation_mode');
        if (($input['load_defaults'] ?? '') === '1') $mode = 'master';
        return ['id' => $id, 'nip' => $nip, 'bulan' => $month, 'tahun' => $year, 'bagian' => $department, 'mode' => $mode];
    }

    private function records(array $request, bool $forUpdate): array
    {
        $before = $request['id'] ? (new PayrollRepository())->find($request['id'], $forUpdate) : null;
        if ($request['id'] && !$before) throw new PayrollValidationException('Transaksi tidak ditemukan.', 'id', 'transaction_not_found');
        if ($before !== null && ($before['nip'] !== $request['nip'] || PayrollPeriod::value($before['bulan'], 'Bulan', 1, 12) !== $request['bulan'] || PayrollPeriod::value($before['tahun'], 'Tahun', 1900, 9999) !== $request['tahun'])) {
            throw new PayrollValidationException('NIP dan periode transaksi tersimpan tidak dapat diganti.', 'nip', 'immutable_transaction_identity');
        }
        $statement = Database::connection()->prepare('SELECT k.nip,k.nama,k.kd_bagian,b.nama_bagian FROM karyawan k LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian WHERE k.nip=?');
        $statement->execute([$request['nip']]); $employee = $statement->fetch();
        if (!$employee && ($before === null || $request['mode'] === 'master')) throw new PayrollValidationException('Karyawan tidak ditemukan pada master.', 'nip', 'employee_not_found');
        if ($before === null) {
            if ($request['bagian'] === '' || $request['bagian'] !== $employee['kd_bagian'] || $employee['nama_bagian'] === null) throw new PayrollValidationException('Bagian tidak sesuai dengan karyawan yang dipilih.', 'bagian', 'employee_department_mismatch');
        } elseif ($request['bagian'] !== (string)$before['kd_bagian']) {
            throw new PayrollValidationException('Bagian transaksi tersimpan tidak dapat diganti.', 'bagian', 'immutable_transaction_department');
        }
        return [$before, $employee ?: null];
    }

    private function identity(array $request, ?array $before): array
    {
        $revision = '';
        if ($before !== null) {
            $keys = array_merge(['id', 'nip', 'bulan', 'tahun', 'source', 'employee_name', 'department_code', 'department_name', 'calculation_version', 'calculation_snapshot', 'updated_at'], array_keys(PayrollFields::numeric()));
            $record = array_intersect_key($before, array_fill_keys($keys, true));
            if (is_string($record['calculation_snapshot'] ?? null)) $record['calculation_snapshot'] = json_decode($record['calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $revision = hash('sha256', json_encode(self::canonical($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return ['id' => $request['id'], 'nip' => $request['nip'], 'bulan' => $request['bulan'], 'tahun' => $request['tahun'], 'mode' => $request['mode'], 'before_revision' => $revision];
    }

    private function confirmation(array $result, array $identity, array $input): array
    {
        [$token, $accepted, $valid] = $this->tokenState($input, $result, $identity);
        if (($token !== '' && !$valid) || ($valid && PayrollConfirmation::requiresAcknowledgement($token) && !$accepted && $result['issues'] === [])) {
            $result['issues'][] = ['code' => 'calculation_changed', 'severity' => 'warning', 'field' => '', 'message' => 'Perhitungan atau data sumber berubah, atau konfirmasi sebelumnya tidak lagi berlaku. Periksa hasil terbaru sebelum menyimpan.'];
        }
        return [...$result, 'confirmation_token' => PayrollConfirmation::issue($result, $identity), 'acknowledged' => $valid && $accepted,
            'notice' => $identity['mode'] === 'master' ? 'Komponen dihitung menggunakan master saat ini. Periksa rincian sebelum menyimpan.' : null];
    }

    private function tokenState(array $input, array $result, array $identity): array
    {
        $token = $this->text($input['confirmation_token'] ?? '', 'confirmation_token', 'Konfirmasi');
        if (strlen($token) > 128) throw new PayrollValidationException('Konfirmasi tidak valid. Hitung ulang transaksi.', 'confirmation_token', 'invalid_confirmation');
        $accepted = ($input['warnings_accepted'] ?? '') === '1';
        return [$token, $accepted, $token !== '' && PayrollConfirmation::valid($token, $result, $identity)];
    }

    private function text(mixed $raw, string $field, string $label): string
    {
        if (!is_string($raw)) throw new PayrollValidationException("$label tidak valid.", $field, 'invalid_input');
        return trim($raw);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) $item = self::canonical($item);
        unset($item); return $value;
    }
}
