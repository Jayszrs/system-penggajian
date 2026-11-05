<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Domain\{PayrollFields, PayrollPeriod, PayrollValidationException};
use DateTimeImmutable;
use InvalidArgumentException;

final class PayrollCalculator
{
    public const VERSION = 'accounting_v2';

    /** SQL numeric(18,0) conversion rounds halves away from zero. */
    public static function round(string $value): string
    {
        if (bccomp($value, '0', 4) < 0) return bcsub('0', bcadd(bcsub('0', $value, 4), '0.5', 0), 0);
        return bcadd($value, '0.5', 0);
    }

    public static function number(mixed $value, string $label, bool $nullable = true): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) return $nullable ? null : '0';
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^-?\d{1,18}$/D', trim((string)$value))) {
            throw new PayrollValidationException("$label harus bilangan bulat rupiah atau jumlah, maksimal 18 digit.", '', 'invalid_number');
        }
        return bcadd(trim((string)$value), '0', 0);
    }

    public function validateAttendance(array $values, ?int $month = null, ?int $year = null): void
    {
        $issues = [];
        foreach (PayrollFields::ATTENDANCE as $field => $label) {
            if (bccomp((string)($values[$field] ?? '0'), '0', 0) < 0) $issues[] = self::issue('negative_attendance', $field, "$label tidak boleh negatif.");
        }
        $effective = (string)($values['hefektif'] ?? '0');
        if (bccomp($effective, '0', 0) <= 0) $issues[] = self::issue('effective_days_required', 'hefektif', 'Hari efektif harus lebih dari nol.');
        if ($month !== null && $year !== null) {
            $calendarDays = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            if (bccomp($effective, $calendarDays, 0) > 0) $issues[] = self::issue('effective_days_exceed_calendar', 'hefektif', "Hari efektif tidak boleh melebihi $calendarDays hari pada periode yang dipilih.");
        }
        $attendance = PayrollRepository::moneySum([$values['hmasuk'] ?? '0', $values['hijin'] ?? '0', $values['halpha'] ?? '0']);
        if (bccomp($attendance, $effective, 0) > 0) $issues[] = self::issue('attendance_exceeds_effective', 'hmasuk', 'Jumlah masuk kerja, izin/alpha, dan sakit tidak boleh melebihi hari efektif.');
        if ($issues) throw PayrollValidationException::fromIssues($issues);
    }

    /** Sum the rounded components that are actually stored, never unrounded intermediates. */
    public function totals(array $values): array
    {
        foreach (array_merge(PayrollFields::INCOME, PayrollFields::DEDUCTIONS) as $field => $label) {
            if (($values[$field] ?? null) === null) continue;
            if (bccomp((string)$values[$field], '0', 4) < 0) throw new PayrollValidationException("$label tidak boleh negatif.", $field, 'negative_amount');
            $values[$field] = self::round((string)$values[$field]);
            $this->validateAmount($values[$field], $field, $label);
        }
        $gross = PayrollRepository::moneySum(array_map(static fn($field) => $values[$field] ?? '0', array_keys(PayrollFields::INCOME)));
        $deductions = PayrollRepository::moneySum(array_map(static fn($field) => $values[$field] ?? '0', array_keys(PayrollFields::DEDUCTIONS)));
        $net = bcsub($gross, $deductions, 0);
        foreach (['tpenghasilan' => $gross, 'tpotongan' => $deductions] as $field => $amount) $this->validateAmount($amount, $field, PayrollFields::TOTALS[$field]);
        if (bccomp($net, '0', 0) < 0) throw new PayrollValidationException('Total potongan melebihi penghasilan bruto. Periksa komponen sebelum menyimpan.', 'tbersih', 'negative_net');
        return [...$values, 'tpenghasilan' => $gross, 'tpotongan' => $deductions, 'tbersih' => $net];
    }

    public function fromInput(array $input, bool $loadDefaults = false): array
    {
        return $this->evaluate($input, $loadDefaults)['values'];
    }

    /** @return array{values:array,issues:array,calculation_version:string,snapshot:array} */
    public function evaluate(array $input, bool $loadDefaults = false, array $context = []): array
    {
        $before = is_array($context['before'] ?? null) ? $context['before'] : null;
        $fields = array_merge(PayrollFields::INCOME, PayrollFields::DEDUCTIONS, PayrollFields::ATTENDANCE);
        $values = []; $issues = [];
        foreach ($fields as $field => $label) {
            $raw = array_key_exists($field, $input) ? $input[$field] : ($before[$field] ?? null);
            $preserveNull = $before !== null && array_key_exists($field, $before) && $before[$field] === null;
            try { $values[$field] = self::number($raw, $label, $preserveNull); }
            catch (PayrollValidationException $exception) { foreach ($exception->issues as $issue) $issues[] = [...$issue, 'field' => $field]; }
        }
        if ($issues) throw PayrollValidationException::fromIssues($issues);
        [$month, $year] = $this->period($input, $context, $before);
        // A no-op edit must not reinterpret, round, or normalize an old payroll row.
        if ($before !== null && !$loadDefaults && $this->unchanged($values, $before, array_keys($fields))) {
            foreach (PayrollFields::TOTALS as $field => $label) $values[$field] = $before[$field] ?? null;
            $version = (string)($before['calculation_version'] ?? 'legacy_v1');
            $snapshot = $before['calculation_snapshot'] ?? null;
            if (is_string($snapshot)) $snapshot = json_decode($snapshot, true);
            if (!is_array($snapshot)) $snapshot = ['policy_version' => $version, 'mode' => 'historical_preserved', 'period' => ['month' => $month, 'year' => $year], 'quantities' => array_intersect_key($values, PayrollFields::ATTENDANCE)];
            return ['values' => $values, 'issues' => [], 'calculation_version' => $version, 'snapshot' => $snapshot];
        }
        $this->validateAttendance($values, $month, $year);
        foreach (array_merge(PayrollFields::INCOME, PayrollFields::DEDUCTIONS) as $field => $label) {
            if ($values[$field] !== null) $this->validateAmount($values[$field], $field, $label);
        }
        $sources = [];
        if ($loadDefaults) $values = $this->loadMaster($input, $values, $sources);
        $values = $this->totals($values);
        $attendance = PayrollRepository::moneySum([$values['hmasuk'] ?? '0', $values['hijin'] ?? '0', $values['halpha'] ?? '0']);
        if (bccomp($attendance, (string)$values['hefektif'], 0) < 0) {
            $difference = bcsub((string)$values['hefektif'], $attendance, 0);
            $issues[] = self::issue('attendance_unclassified', 'hmasuk', "Masih ada $difference hari efektif yang belum diklasifikasikan. Selisih ini tidak otomatis dianggap alpha.", 'warning');
        }
        if (!$loadDefaults) $issues[] = self::issue('manual_override', 'calculation_mode', 'Komponen transaksi diisi atau diubah manual. Pastikan nominal dan hubungan dengan kehadiran sudah diperiksa.', 'warning');
        elseif ($before !== null) $issues[] = self::issue('historical_master_recalculation', 'calculation_mode', 'Transaksi tersimpan dihitung ulang menggunakan master saat ini dan aturan baru, termasuk potongan lain-lain. Periksa perubahan sebelum menyimpan.', 'warning');
        $snapshot = [
            'policy_version' => self::VERSION, 'mode' => $loadDefaults ? 'master' : 'manual',
            'period' => ['month' => $month, 'year' => $year],
            'policy' => ['rounding' => 'component_half_away_from_zero', 'daily_rate_source' => 'karyawan.t_harian', 'overtime_rate_source' => 'karyawan.t_lembur', 'absence_weights' => ['hijin' => '1', 'halpha' => '0.5'], 'p_lain_target' => 'plain'],
            'quantities' => array_intersect_key($values, PayrollFields::ATTENDANCE), 'sources' => $sources,
        ];
        return ['values' => $values, 'issues' => $issues, 'calculation_version' => self::VERSION, 'snapshot' => $snapshot];
    }

    private function loadMaster(array $input, array $values, array &$sources): array
    {
        $db = Database::connection(); $nip = trim((string)($input['nip'] ?? ''));
        $statement = $db->prepare('SELECT nip,kd_bagian,gapok,t_jabatan,t_harian,t_lembur FROM karyawan WHERE nip=?');
        $statement->execute([$nip]); $employee = $statement->fetch();
        if (!$employee) throw new PayrollValidationException('Pilih karyawan yang terdaftar terlebih dahulu.', 'nip', 'employee_not_found');
        $sources['karyawan'] = $employee;
        $masterRate = function (mixed $value, string $field, string $label, bool $required): string {
            if ($value === null && $required) throw new PayrollValidationException("$label belum diatur pada master. Isi tarif secara eksplisit; nol tetap diperbolehkan.", $field, 'missing_required_rate');
            try { $amount = self::number($value, $label, false); }
            catch (PayrollValidationException $exception) { throw new PayrollValidationException("$label pada master tidak valid.", $field, 'invalid_master_rate'); }
            $this->validateAmount($amount, $field, $label); return $amount;
        };
        $rate = function (string $table, string $keyField, string $key, string $field, string $label, bool $required, bool $requireExistingValue = false) use ($db, &$sources, $masterRate): string {
            $statement = $db->prepare("SELECT jumlah FROM `$table` WHERE `$keyField`=?"); $statement->execute([$key]); $row = $statement->fetch();
            $sources[$table] = ['exists' => $row !== false, $keyField => $key, 'jumlah' => $row === false ? null : $row['jumlah']];
            if ($row === false && !$required) return '0';
            return $masterRate($row === false ? null : $row['jumlah'], $field, $label, $required || ($requireExistingValue && $row !== false));
        };
        $positive = static fn(mixed $value): bool => bccomp((string)($value ?? '0'), '0', 0) > 0;
        $values['gapok'] = $masterRate($employee['gapok'], 'gapok', 'Gaji pokok', true);
        $values['t_jabatan'] = $masterRate($employee['t_jabatan'], 't_jabatan', 'Tunjangan jabatan', true);
        $daily = $masterRate($employee['t_harian'], 't_harian', 'Tarif harian karyawan', true);
        $overtime = $masterRate($employee['t_lembur'], 'ulembur', 'Tarif lembur karyawan', $positive($values['lembur']));
        $values['t_harian'] = bcmul($daily, (string)$values['hefektif'], 4);
        $values['t_konsumsi'] = bcmul($rate('tunj_konsumsi', 'kd_bagian', $employee['kd_bagian'], 't_konsumsi', 'Tarif konsumsi', $positive($values['hmasuk'])), (string)($values['hmasuk'] ?? '0'), 4);
        $values['ulembur'] = bcmul($overtime, (string)($values['lembur'] ?? '0'), 4);
        $values['uinfal'] = bcmul($rate('tunj_infal', 'kd_bagian', $employee['kd_bagian'], 'uinfal', 'Tarif infal', $positive($values['infal'])), (string)($values['infal'] ?? '0'), 4);
        $values['uovertime'] = bcmul($rate('tunj_overtime', 'kd_bagian', $employee['kd_bagian'], 'uovertime', 'Tarif overtime', $positive($values['overtime'])), (string)($values['overtime'] ?? '0'), 4);
        $weighted = bcadd((string)($values['hijin'] ?? '0'), bcmul((string)($values['halpha'] ?? '0'), '0.5', 4), 4);
        $values['absensi'] = bcmul($daily, $weighted, 4);
        foreach (['p_anak' => 'p_anak', 'p_bjbs' => 'pinjaman_bsm', 'p_bank' => 'arisan', 'p_lunak' => 'pinjaman_lunak', 'p_lain' => 'plain'] as $table => $field) $values[$field] = $rate($table, 'nip', $employee['nip'], $field, PayrollFields::DEDUCTIONS[$field], false, true);
        $statement = $db->prepare('SELECT jumlah,tarip FROM tunj_jam WHERE nip=?'); $statement->execute([$employee['nip']]); $hours = $statement->fetch();
        $sources['tunj_jam'] = ['exists' => $hours !== false, 'nip' => $employee['nip'], 'jumlah' => $hours === false ? null : $hours['jumlah'], 'tarip' => $hours === false ? null : $hours['tarip']];
        if ($hours === false) $values['kelebihan'] = '0';
        else {
            $quantity = $masterRate($hours['jumlah'], 'kelebihan', 'Jumlah kelebihan mengajar', true);
            $teachingRate = $masterRate($hours['tarip'], 'kelebihan', 'Tarif kelebihan mengajar', $positive($quantity));
            $values['kelebihan'] = bcmul($quantity, $teachingRate, 4);
        }
        return $values;
    }

    private function period(array $input, array $context, ?array $before): array
    {
        $parts = [];
        foreach (['bulan' => ['month', 'Bulan', 1, 12], 'tahun' => ['year', 'Tahun', 1900, 9999]] as $field => [$contextField, $label, $min, $max]) {
            $providedByInput = array_key_exists($field, $input);
            $raw = $providedByInput ? $input[$field] : ($context[$contextField] ?? $before[$field] ?? null);
            if ($raw === null && !$providedByInput) { $parts[$field] = null; continue; }
            try {
                $parts[$field] = PayrollPeriod::value($raw, $label, $min, $max);
                if (array_key_exists($contextField, $context) && $context[$contextField] !== null) {
                    $provided = PayrollPeriod::value($context[$contextField], $label, $min, $max);
                    if ($provided !== $parts[$field]) throw new InvalidArgumentException("$label tidak sesuai dengan konteks transaksi.");
                }
            } catch (InvalidArgumentException $exception) { throw new PayrollValidationException($exception->getMessage(), $field, 'invalid_period'); }
        }
        if (($parts['bulan'] === null) !== ($parts['tahun'] === null)) throw new PayrollValidationException('Bulan dan tahun harus diisi bersama.', $parts['bulan'] === null ? 'bulan' : 'tahun', 'incomplete_period');
        return [$parts['bulan'], $parts['tahun']];
    }

    private function unchanged(array $values, array $before, array $fields): bool
    {
        foreach ($fields as $field) {
            $original = $before[$field] ?? null;
            if ($original === null || $values[$field] === null) { if ($original !== $values[$field]) return false; }
            elseif ((string)$original !== $values[$field]) return false;
        }
        return true;
    }

    private function validateAmount(string $amount, string $field, string $label): void
    {
        if (bccomp($amount, '0', 0) < 0) throw new PayrollValidationException("$label tidak boleh negatif.", $field, 'negative_amount');
        if (strlen(ltrim($amount, '-')) > 18) throw new PayrollValidationException("$label melebihi batas penyimpanan 18 digit.", $field, 'amount_overflow');
    }

    private static function issue(string $code, string $field, string $message, string $severity = 'error'): array
    {
        return ['code' => $code, 'severity' => $severity, 'field' => $field, 'message' => $message];
    }
}
