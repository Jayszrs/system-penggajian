<?php
declare(strict_types=1);
$_ENV['DB_DATABASE'] = 'penggajian_test';
require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Database;
use App\Domain\{PayrollFields, PayrollValidationException};
use App\Services\{PayrollConfirmation, PayrollWorkflow};

if (($_ENV['DB_DATABASE'] ?? '') !== 'penggajian_test') throw new RuntimeException('Refusing non-test database.');
$db = Database::connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'penggajian_test') throw new RuntimeException('Refusing non-test connection.');
$_SESSION = []; $workflow = new PayrollWorkflow(); $checks = 0;
$department = 'W'.strtoupper(bin2hex(random_bytes(2)));
$nip = '000W'.bin2hex(random_bytes(5));
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$rejects = static function (callable $work, string $code) use ($check): void {
    try { $work(); throw new RuntimeException('Expected '.$code); }
    catch (PayrollValidationException $exception) { $check(in_array($code, array_column($exception->issues, 'code'), true), $code); }
};
$count = static function () use ($db, $nip): int { $statement=$db->prepare('SELECT COUNT(*) FROM penghasilan WHERE nip=?');$statement->execute([$nip]);return (int)$statement->fetchColumn(); };
$base = [...array_fill_keys(array_keys(PayrollFields::numeric()), '0'), 'id' => '0', 'nip' => $nip, 'bagian' => $department, 'bulan' => '02', 'tahun' => '2024', 'calculation_mode' => 'manual', 'hefektif' => '21', 'hmasuk' => '21', 'gapok' => '1000000'];
try {
    $db->beginTransaction();
    $db->prepare('INSERT INTO bagian(kd_bagian,nama_bagian) VALUES(?,?)')->execute([$department, 'Workflow synthetic fixture']);
    $db->prepare('INSERT INTO karyawan(nip,nama,kd_bagian,gapok,t_jabatan,t_harian,t_lembur) VALUES(?,?,?,?,?,?,?)')->execute([$nip, 'Workflow synthetic fixture', $department, '1000000', '200000', '20001', '7500']);
    foreach (['tunj_konsumsi' => '12000', 'tunj_infal' => '12500', 'tunj_overtime' => '8000'] as $table => $amount) $db->prepare("INSERT INTO `$table`(kd_bagian,jumlah) VALUES(?,?)")->execute([$department, $amount]);
    $db->prepare('INSERT INTO p_lain(nip,jumlah) VALUES(?,?)')->execute([$nip, '11111']);
    $db->commit();

    foreach (['1x', '-1', ['1'], '9223372036854775808'] as $badId) $rejects(fn() => PayrollWorkflow::id($badId), 'invalid_transaction_id');
    $pending = $workflow->save($base);
    $check($pending['saved'] === false && $count() === 0, 'Unacknowledged manual save writes no record');
    $check(in_array('manual_override', array_column($pending['calculation']['issues'], 'code'), true), 'Pending result explains the required review');
    $check(PayrollConfirmation::requiresAcknowledgement($pending['calculation']['confirmation_token']), 'Issued warning token carries acknowledgement requirement');
    $check($workflow->save([...$base, 'warnings_accepted' => '1'])['saved'] === false, 'A checkbox without a bound token is insufficient');
    $confirmed = [...$base, 'confirmation_token' => $pending['calculation']['confirmation_token'], 'warnings_accepted' => '1'];
    $_SESSION['user']['id'] = 'invalid-user-for-rollback-test';
    try { $workflow->save($confirmed); throw new RuntimeException('Expected audit SQL failure'); }
    catch (PDOException $exception) {
        $check($count() === 0 && !$db->inTransaction(), 'Audit SQL failure rolls back the preceding payroll insert');
        $check(isset($_SESSION['payroll_confirmations'][$confirmed['confirmation_token']]), 'Failed SQL does not consume acknowledgement token');
    }
    unset($_SESSION['user']);
    $saved = $workflow->save($confirmed); $manualId = $saved['saved'];
    $check(is_int($manualId) && $manualId > 0 && $count() === 1, 'Confirmed manual transaction is committed');
    $check(!isset($_SESSION['payroll_confirmations'][$confirmed['confirmation_token']]), 'Successful commit consumes token');
    $statement = $db->prepare('SELECT * FROM penghasilan WHERE id=?'); $statement->execute([$manualId]); $manual = $statement->fetch();
    $check($manual['nip'] === $nip && $manual['calculation_version'] === 'accounting_v2', 'Leading-zero NIP and calculation version are persisted');
    $check($manual['tbersih'] === '1000000', 'Server ignores client totals');
    $statement = $db->prepare("SELECT after_data FROM audit_log WHERE entity='penghasilan' AND record_key=? ORDER BY id DESC LIMIT 1"); $statement->execute([(string)$manualId]); $audit = json_decode($statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $check($audit['_calculation']['acknowledged'] === true, 'Audit records acknowledgement and calculation metadata');
    $manualInput = [...$base, 'id' => (string)$manualId];
    $manualPreview = $workflow->preview($manualInput);
    $check($manualPreview['issues'] === [] && $manualPreview['calculation_version'] === 'accounting_v2', 'Existing unchanged version-2 transaction does not repeat manual warning');
    $check($manualPreview['snapshot'] === json_decode($manual['calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR), 'Existing version-2 preview preserves stored snapshot after MySQL JSON normalization');
    $manualRepeat = $workflow->preview([...$manualInput, 'confirmation_token' => $manualPreview['confirmation_token']]);
    $check($manualRepeat['issues'] === [], 'Re-reading normalized stored JSON does not invalidate the preview');
    $manualNoop = $workflow->save([...$manualInput, 'confirmation_token' => $manualRepeat['confirmation_token']]);
    $check($manualNoop['saved'] === $manualId, 'Unchanged version-2 record saves without a new acknowledgement');
    $statement = $db->prepare('SELECT calculation_snapshot,source,employee_name,department_code,department_name FROM penghasilan WHERE id=?'); $statement->execute([$manualId]); $manualAfter = $statement->fetch();
    foreach ($manualAfter as $field => $value) $check($value === $manual[$field], 'Unchanged version-2 metadata remains stable: '.$field);
    $rejects(fn() => $workflow->save($base), 'duplicate_period');
    $check($count() === 1, 'Duplicate rejection leaves the existing transaction intact');
    $rejects(fn() => $workflow->preview([...$base, 'id' => (string)$manualId, 'bulan' => '03']), 'immutable_transaction_identity');
    $rejects(fn() => $workflow->preview([...$base, 'bulan' => '2x']), 'invalid_period');
    $rejects(fn() => $workflow->preview([...$base, 'bagian' => 'OTHER']), 'employee_department_mismatch');
    $rejects(fn() => $workflow->preview([...$base, 'bagian' => '']), 'employee_department_mismatch');
    $optionalQuantities = ['hijin', 'halpha', 'lembur', 'infal', 'overtime'];
    $withoutQuantities = $workflow->preview(array_diff_key($base, array_fill_keys($optionalQuantities, true)));
    foreach ($optionalQuantities as $field) $check($withoutQuantities['values'][$field] === '0', 'Missing optional quantity is zero: '.$field);

    $masterInput = [...$base, 'bulan' => '03', 'calculation_mode' => 'master', 'hmasuk' => '18', 'hijin' => '2', 'halpha' => '1', 'infal' => '2', 'plain' => '999', 'lain1' => '4444'];
    $preview = $workflow->preview($masterInput);
    $check($preview['issues'] === [] && !$preview['acknowledged'], 'Complete new master preview does not invent a warning');
    $check($preview['values']['plain'] === '11111' && $preview['values']['lain1'] === '4444', 'Master mapping applies deduction once and retains manual income');
    $db->prepare('UPDATE tunj_infal SET jumlah=14000 WHERE kd_bagian=?')->execute([$department]);
    $stale = $workflow->save([...$masterInput, 'confirmation_token' => $preview['confirmation_token'], 'warnings_accepted' => '1']);
    $check($stale['saved'] === false && $count() === 1, 'Changed source rates invalidate an acknowledged preview');
    $check(in_array('calculation_changed', array_column($stale['calculation']['issues'], 'code'), true), 'Stale preview reports changed calculation');
    $unaccepted = $workflow->save([...$masterInput, 'confirmation_token' => $stale['calculation']['confirmation_token']]);
    $check($unaccepted['saved'] === false && $count() === 1, 'Transient warning cannot be bypassed by omitting acceptance');
    $repeat = $workflow->preview([...$masterInput, 'confirmation_token' => $unaccepted['calculation']['confirmation_token']]);
    $check(in_array('calculation_changed', array_column($repeat['issues'], 'code'), true), 'Preview cannot erase a pending transient acknowledgement');
    $masterSaved = $workflow->save([...$masterInput, 'confirmation_token' => $repeat['confirmation_token'], 'warnings_accepted' => '1']);
    $check(is_int($masterSaved['saved']) && $count() === 2, 'Fresh acknowledged calculation commits successfully');
    $check($masterSaved['calculation']['values']['uinfal'] === '28000', 'Saved calculation uses current rates');
    $masterExisting = [...$masterInput, ...$masterSaved['calculation']['values'], 'id' => (string)$masterSaved['saved']];
    $masterReload = $workflow->preview($masterExisting);
    $check(in_array('historical_master_recalculation', array_column($masterReload['issues'], 'code'), true), 'Explicit version-2 master reload still requests review');
    $masterApplied = [...$masterExisting, ...$masterReload['values'], 'confirmation_token' => $masterReload['confirmation_token'], 'warnings_accepted' => '1'];
    $masterResaved = $workflow->save($masterApplied);
    $check($masterResaved['saved'] === $masterSaved['saved'], 'Applying preview values to inputs does not invalidate master acknowledgement');
    $masterManualView = [...$masterApplied, 'calculation_mode' => 'manual', 'confirmation_token' => '', 'warnings_accepted' => '0'];
    $masterPreserved = $workflow->preview($masterManualView);
    $check($masterPreserved['issues'] === [] && $masterPreserved['snapshot']['mode'] === 'master', 'Manual no-op view preserves original master calculation provenance');

    $legacyValues = array_intersect_key($base, array_fill_keys(PayrollFields::columns(), true));
    $legacyValues = [...$legacyValues, 'bulan' => '04', 'tpenghasilan' => '1000000', 'tpotongan' => '0', 'tbersih' => '1000000', 'cash_bon' => null, 'source' => 'legacy', 'employee_name' => 'Workflow synthetic fixture', 'department_code' => $department, 'department_name' => 'Workflow synthetic fixture', 'calculation_version' => 'legacy_v1', 'calculation_snapshot' => null];
    $statement = $db->prepare('INSERT INTO penghasilan (`'.implode('`,`', array_keys($legacyValues)).'`) VALUES('.implode(',', array_fill(0, count($legacyValues), '?')).')'); $statement->execute(array_values($legacyValues)); $legacyId = (int)$db->lastInsertId();
    $legacyInput = [...$base, ...$legacyValues, 'id' => (string)$legacyId, 'cash_bon' => ''];
    $legacySaved = $workflow->save($legacyInput);
    $statement = $db->prepare('SELECT * FROM penghasilan WHERE id=?'); $statement->execute([$legacyId]); $legacyAfter = $statement->fetch();
    $check($legacySaved['saved'] === $legacyId && $legacyAfter['cash_bon'] === null, 'No-op legacy save preserves original NULL');
    $check($legacyAfter['calculation_version'] === 'legacy_v1' && $legacyAfter['calculation_snapshot'] === null, 'No-op legacy save does not populate version-2 metadata');
    foreach (PayrollFields::columns() as $field) $check($legacyAfter[$field] === $legacyValues[$field], 'Historical field remains identical: '.$field);
    $oldPreview = $workflow->preview($legacyInput);
    $db->prepare('UPDATE penghasilan SET gapok=1000001,tpenghasilan=1000001,tbersih=1000001 WHERE id=?')->execute([$legacyId]);
    $revisionStale = $workflow->save([...$legacyInput, 'confirmation_token' => $oldPreview['confirmation_token'], 'warnings_accepted' => '1']);
    $check($revisionStale['saved'] === false && in_array('calculation_changed', array_column($revisionStale['calculation']['issues'], 'code'), true), 'Concurrent saved-row revision invalidates preview');
    $statement = $db->prepare('SELECT gapok FROM penghasilan WHERE id=?'); $statement->execute([$legacyId]);
    $check($statement->fetchColumn() === '1000001', 'Rejected stale save does not overwrite concurrent changes');
    echo "PASS: $checks workflow accounting checks; synthetic fixtures cleaned from reserved test database.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->beginTransaction();
    $statement = $db->prepare('SELECT id FROM penghasilan WHERE nip=?'); $statement->execute([$nip]); $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) $db->prepare("DELETE FROM audit_log WHERE entity='penghasilan' AND record_key=?")->execute([(string)$id]);
    foreach (['penghasilan', 'p_lain', 'karyawan'] as $table) $db->prepare("DELETE FROM `$table` WHERE nip=?")->execute([$nip]);
    foreach (['tunj_konsumsi', 'tunj_infal', 'tunj_overtime', 'bagian'] as $table) $db->prepare("DELETE FROM `$table` WHERE kd_bagian=?")->execute([$department]);
    $db->commit();
}
