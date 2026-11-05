<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\Domain\{PayrollFields, PayrollValidationException};
use App\Services\PayrollCalculator;

// Pure arithmetic and validation fixtures: this script never opens a database.
$checks = 0;
$calculator = new PayrollCalculator();
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$rejects = static function (callable $work, string $code, string $field) use ($check): void {
    try { $work(); throw new RuntimeException('Expected validation error: '.$code); }
    catch (PayrollValidationException $exception) {
        $check(in_array($code, array_column($exception->issues, 'code'), true), $code.' issue code');
        $check(in_array($field, array_column($exception->issues, 'field'), true), $code.' field');
        $check(count(array_filter($exception->issues, static fn($issue) => $issue['severity'] !== 'error')) === 0, 'Errors are structured');
    }
};
$base = [...array_fill_keys(array_keys(PayrollFields::numeric()), '0'), 'hefektif' => '21', 'hmasuk' => '21', 'gapok' => '1000000', 'bulan' => '02', 'tahun' => '2024'];
$result = $calculator->evaluate($base);
$check($result['calculation_version'] === 'accounting_v2', 'New transaction has explicit calculation version');
$check($result['values']['tbersih'] === '1000000', 'Integer values are exact');
$check(in_array('manual_override', array_column($result['issues'], 'code'), true), 'Manual values require review');
$check($result === $calculator->evaluate($base), 'Evaluation and snapshot are deterministic');
$check($result['snapshot']['period'] === ['month' => 2, 'year' => 2024], 'Leading-zero month is normalized strictly');
$check($calculator->fromInput([...$base, 'lain_lain' => '   '])['lain_lain'] === '0', 'Whitespace-only optional value is blank');

$fractional = $calculator->totals(['gapok' => '2009632', 'cash_bon' => '218000', 'absensi' => '50002.5']);
$check($fractional['absensi'] === '50003', 'Half-rupiah component rounds before totals');
$check($fractional['tpenghasilan'] === '2009632' && $fractional['tpotongan'] === '268003' && $fractional['tbersih'] === '1741629', 'Independent expected totals balance after rounding');
$second = $calculator->fromInput([...$base, ...$fractional]);
$check($second['tbersih'] === $fractional['tbersih'], 'Saving rounded components is idempotent');
$multi = $calculator->totals(['gapok' => '100', 't_jabatan' => '0.5', 't_harian' => '0.5', 'absensi' => '0.5']);
$check($multi['tpenghasilan'] === '102' && $multi['tpotongan'] === '1' && $multi['tbersih'] === '101', 'Each component rounds separately');

$blank = $calculator->evaluate(['hefektif' => '21', 'hmasuk' => '21']);
foreach (array_merge(PayrollFields::INCOME, PayrollFields::DEDUCTIONS) as $field => $label) $check($blank['values'][$field] === '0', 'Optional blank new component is zero: '.$field);
$tampered = $calculator->fromInput([...$base, 'tpenghasilan' => 'wrong', 'tpotongan' => ['wrong'], 'tbersih' => '-999']);
$check($tampered['tpenghasilan'] === '1000000' && $tampered['tpotongan'] === '0' && $tampered['tbersih'] === '1000000', 'Client totals are ignored completely');

$before = [...$base, ...$result['values'], 'source' => 'legacy', 'calculation_version' => 'legacy_v1', 'calculation_snapshot' => null, 'cash_bon' => null];
$preserved = $calculator->evaluate([...$before, 'cash_bon' => '', 'tbersih' => '1'], false, ['before' => $before]);
$check($preserved['values']['cash_bon'] === null, 'Unchanged legacy NULL remains NULL');
$check($preserved['values']['tbersih'] === '1000000' && $preserved['calculation_version'] === 'legacy_v1', 'No-op legacy edit preserves stored total and version');
$check($preserved['issues'] === [], 'No-op edit does not repeat warnings');
$changed = $calculator->evaluate([...$before, 'gapok' => '1000001', 'cash_bon' => ''], false, ['before' => $before]);
$check($changed['calculation_version'] === 'accounting_v2' && $changed['values']['tbersih'] === '1000001', 'Manual edit upgrades calculation version');
$check($changed['values']['cash_bon'] === null, 'Unchanged legacy NULL field is retained during another manual edit');
$saved = [...$base, ...$changed['values'], 'calculation_version' => $changed['calculation_version'], 'calculation_snapshot' => json_encode($changed['snapshot'])];
$unchangedV2 = $calculator->evaluate($saved, false, ['before' => $saved]);
$check($unchangedV2['snapshot'] === $changed['snapshot'] && $unchangedV2['issues'] === [], 'No-op v2 edit retains its snapshot');

$short = $calculator->evaluate([...$base, 'hmasuk' => '20']);
$check(in_array('attendance_unclassified', array_column($short['issues'], 'code'), true), 'Unclassified attendance is a warning, never automatic alpha');
$check($short['values']['absensi'] === '0', 'Unclassified attendance does not invent a deduction');
$rejects(fn() => $calculator->evaluate([...$base, 'gapok' => '-1']), 'negative_amount', 'gapok');
$rejects(fn() => $calculator->evaluate([...$base, 'plain' => '-1']), 'negative_amount', 'plain');
$rejects(fn() => $calculator->evaluate([...$base, 'lembur' => '-1']), 'negative_attendance', 'lembur');
$rejects(fn() => $calculator->evaluate([...$base, 'plain' => '1000001']), 'negative_net', 'tbersih');
$rejects(fn() => $calculator->evaluate([...$base, 'gapok' => '1e6']), 'invalid_number', 'gapok');
$rejects(fn() => $calculator->evaluate([...$base, 'gapok' => '1000000.00']), 'invalid_number', 'gapok');
$rejects(fn() => $calculator->evaluate([...$base, 'gapok' => ['1']]), 'invalid_number', 'gapok');
$rejects(fn() => $calculator->evaluate([...$base, 'gapok' => '1000000000000000000']), 'invalid_number', 'gapok');
$rejects(fn() => $calculator->evaluate([...$base, 'gapok' => '999999999999999999', 't_jabatan' => '1']), 'amount_overflow', 'tpenghasilan');
$largest = $calculator->fromInput([...$base, 'gapok' => '999999999999999999']);
$check($largest['tbersih'] === '999999999999999999', '18-digit boundary never loses integer precision');
$rejects(fn() => $calculator->evaluate([...$base, 'hefektif' => '0']), 'effective_days_required', 'hefektif');
$rejects(fn() => $calculator->evaluate([...$base, 'hmasuk' => '22']), 'attendance_exceeds_effective', 'hmasuk');
$rejects(fn() => $calculator->evaluate([...$base, 'hefektif' => '30', 'hmasuk' => '30']), 'effective_days_exceed_calendar', 'hefektif');
$leap = $calculator->fromInput([...$base, 'hefektif' => '29', 'hmasuk' => '29']);
$check($leap['hefektif'] === '29', 'Leap-year February accepts 29 effective days');
$rejects(fn() => $calculator->evaluate([...$base, 'tahun' => '2025', 'hefektif' => '29', 'hmasuk' => '29']), 'effective_days_exceed_calendar', 'hefektif');
$rejects(fn() => $calculator->evaluate([...$base, 'bulan' => '2x']), 'invalid_period', 'bulan');
$rejects(fn() => $calculator->evaluate([...$base, 'bulan' => null], false, ['month' => 2, 'year' => 2024]), 'invalid_period', 'bulan');
$rejects(fn() => $calculator->evaluate([...$base, 'tahun' => '2024x']), 'invalid_period', 'tahun');
$rejects(fn() => $calculator->evaluate($base, false, ['month' => 3, 'year' => 2024]), 'invalid_period', 'bulan');
echo "PASS: $checks accounting calculation checks; no database connection.\n";
