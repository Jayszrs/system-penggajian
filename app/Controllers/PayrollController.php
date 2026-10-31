<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Controller, Database};
use App\Services\{PayrollRepository, PayrollWorkflow};
use App\Domain\{PayrollFields, PayrollValidationException};
use InvalidArgumentException;
use PDOException;

final class PayrollController extends Controller
{
    public function index(): void
    {
        $db = Database::connection(); $repo = new PayrollRepository(); $error = null;
        try { $period = $repo->period(); }
        catch (PayrollValidationException $exception) { http_response_code(422); $this->view('error', ['errorMessage' => $exception->getMessage()], false); return; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCsrf();
            try {
                $id = PayrollWorkflow::id($_POST['id'] ?? '0');
                if (!$id) throw new PayrollValidationException('Pilih transaksi yang akan dihapus.', 'id', 'invalid_transaction_id');
                $db->beginTransaction();
                $statement = $db->prepare('SELECT * FROM penghasilan WHERE id=? FOR UPDATE'); $statement->execute([$id]); $before = $statement->fetch();
                if (!$before) throw new PayrollValidationException('Transaksi tidak ditemukan.', 'id', 'transaction_not_found');
                $db->prepare('DELETE FROM penghasilan WHERE id=?')->execute([$id]);
                $this->audit('delete', 'penghasilan', (string)$id, $before); $db->commit();
                flash('success', 'Transaksi berhasil dihapus.'); redirect(url('transaksi', $period));
            } catch (InvalidArgumentException|PDOException $exception) {
                if ($db->inTransaction()) $db->rollBack();
                $error = $exception instanceof PDOException ? 'Transaksi belum berhasil dihapus. Silakan coba kembali.' : $exception->getMessage();
                http_response_code($exception instanceof PDOException ? 500 : 422);
            }
        }
        [$where, $params] = $repo::scope($period);
        $search = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
        $employeeName = $repo::employeeNameSql(); $departmentCode = $repo::departmentCodeSql(); $departmentName = $repo::departmentNameSql();
        if ($search !== '') { $where .= " AND (p.nip LIKE ? OR $employeeName LIKE ?)"; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
        $join = " FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=$departmentCode";
        $statement = $db->prepare("SELECT COUNT(*) AS n,COALESCE(SUM(p.tpenghasilan),0) AS gross,COALESCE(SUM(p.tpotongan),0) AS deductions,COALESCE(SUM(p.tbersih),0) AS net $join WHERE $where"); $statement->execute($params); $summary = $statement->fetch();
        $page = max(1, min(1000000, is_scalar($_GET['page'] ?? 1) ? (int)($_GET['page'] ?? 1) : 1)); $offset = ($page - 1) * 25;
        $statement = $db->prepare("SELECT p.*,$employeeName AS nama,$departmentName AS nama_bagian $join WHERE $where ORDER BY p.nip,p.id LIMIT 25 OFFSET $offset"); $statement->execute($params);
        $this->view('payroll-list', ['title' => 'Transaksi Penghasilan', 'section' => 'Transaksi', 'period' => $period, 'departments' => $repo->departments(), 'rows' => $statement->fetchAll(), 'page' => $page, 'search' => $search, 'summary' => $summary, 'error' => $error]);
    }

    public function form(): void
    {
        $repo = new PayrollRepository(); $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        try { $id = PayrollWorkflow::id($isPost ? ($_POST['id'] ?? '0') : ($_GET['id'] ?? '0')); }
        catch (PayrollValidationException $exception) { $this->formFailure($exception, 422); return; }
        $before = $id ? $repo->find($id) : false;
        if ($id && !$before) { $this->formFailure(new PayrollValidationException('Transaksi tidak ditemukan.', 'id', 'transaction_not_found'), 404); return; }
        try {
            $period = $isPost
                ? ['bulan' => (int)($before['bulan'] ?? date('n')), 'tahun' => (int)($before['tahun'] ?? date('Y')), 'bagian' => (string)($before['kd_bagian'] ?? '')]
                : $repo->period();
        } catch (PayrollValidationException $exception) { $this->formFailure($exception, 422); return; }
        $values = $before ?: array_fill_keys(array_keys(PayrollFields::numeric()), '0');
        $values['bulan'] = $before['bulan'] ?? $period['bulan']; $values['tahun'] = $before['tahun'] ?? $period['tahun'];
        $mode = $before ? 'manual' : 'master'; $error = null; $feedback = null; $confirmationToken = ''; $warningsAccepted = false;
        if ($isPost) {
            $this->validatePayrollCsrf();
            foreach (array_merge(array_keys(PayrollFields::numeric()), ['nip', 'bulan', 'tahun', 'employee_name', 'bagian']) as $field) {
                if (array_key_exists($field, $_POST)) $values[$field] = is_scalar($_POST[$field]) || $_POST[$field] === null ? $_POST[$field] : '';
            }
            $mode = is_string($_POST['calculation_mode'] ?? null) && in_array($_POST['calculation_mode'], ['manual', 'master'], true) ? $_POST['calculation_mode'] : 'manual';
            try {
                $outcome = (new PayrollWorkflow())->save($_POST);
                if ($outcome['saved'] !== false) {
                    $redirect = url('transaksi/form', ['id' => $outcome['saved']]);
                    flash('success', 'Penghasilan berhasil disimpan.');
                    if ($this->ajax()) json_response(['saved' => true, 'redirect' => $redirect]);
                    redirect($redirect);
                }
                $calculation = $outcome['calculation'];
                if ($this->ajax()) json_response(['saved' => false, ...$calculation, 'requires_confirmation' => true], 422);
                $values = [...$values, ...$calculation['values']];
                $confirmationToken = $calculation['confirmation_token'];
                $feedback = ['severity' => 'warning', 'title' => 'Periksa sebelum menyimpan', 'message' => 'Periksa rincian berikut dan konfirmasikan hasil terbaru sebelum menyimpan.', 'issues' => $calculation['issues'], 'confirmation' => true];
                http_response_code(422);
            } catch (InvalidArgumentException|PDOException $exception) {
                $error = $exception instanceof PDOException ? 'Penghasilan belum berhasil disimpan. Silakan coba kembali; isian Anda tetap tersedia.' : $exception->getMessage();
                $issues = $exception instanceof PayrollValidationException ? $exception->issues : [['code' => $exception instanceof PDOException ? 'database_error' : 'invalid_input', 'severity' => 'error', 'field' => '', 'message' => $error]];
                $status = $exception instanceof PDOException ? 500 : 422;
                if ($this->ajax()) json_response(['saved' => false, 'error' => $error, 'issues' => $issues], $status);
                $feedback = ['severity' => 'error', 'title' => 'Penghasilan belum disimpan', 'message' => $error, 'issues' => $issues, 'confirmation' => false];
                http_response_code($status);
            }
        }
        $this->view('payroll-form', [
            'title' => $id ? 'Rincian Penghasilan' : 'Transaksi Baru', 'section' => 'Transaksi', 'values' => $values,
            'id' => $id, 'error' => $error, 'feedback' => $feedback, 'confirmationToken' => $confirmationToken, 'warningsAccepted' => $warningsAccepted,
            'mode' => $mode, 'departments' => $repo->departments(), 'employeeName' => $before['nama'] ?? ($values['employee_name'] ?? ''),
            'department' => $before['kd_bagian'] ?? ($values['bagian'] ?? $period['bagian']), 'before' => $before,
        ]);
    }

    public function employees(): void
    {
        if (!is_string($_GET['q'] ?? '') || !is_string($_GET['bagian'] ?? '')) json_response(['error' => 'Pencarian karyawan tidak valid.', 'issues' => [['code' => 'invalid_search', 'severity' => 'error', 'field' => 'q', 'message' => 'Pencarian karyawan tidak valid.']]], 422);
        $search = mb_substr(trim($_GET['q'] ?? ''), 0, 100); $department = trim($_GET['bagian'] ?? '');
        if (strlen($department) > 5) json_response(['error' => 'Kode bagian tidak valid.'], 422);
        $where = '(k.nip LIKE ? OR k.nama LIKE ?)'; $params = ['%'.$search.'%', '%'.$search.'%'];
        if ($department !== '') { $where .= ' AND k.kd_bagian=?'; $params[] = $department; }
        $statement = Database::connection()->prepare("SELECT k.nip,k.nama,k.kd_bagian,b.nama_bagian FROM karyawan k LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian WHERE $where ORDER BY k.nama LIMIT 100"); $statement->execute($params);
        json_response(['employees' => $statement->fetchAll()]);
    }

    public function departments(): void { json_response(['departments' => (new PayrollRepository())->departments()]); }

    public function calculate(): void
    {
        $this->validateCsrf();
        try { json_response((new PayrollWorkflow())->preview($_POST)); }
        catch (InvalidArgumentException|PDOException $exception) {
            $error = $exception instanceof PDOException ? 'Perhitungan belum berhasil. Silakan coba kembali.' : $exception->getMessage();
            $issues = $exception instanceof PayrollValidationException ? $exception->issues : [['code' => $exception instanceof PDOException ? 'database_error' : 'invalid_input', 'severity' => 'error', 'field' => '', 'message' => $error]];
            json_response(['error' => $error, 'issues' => $issues], $exception instanceof PDOException ? 500 : 422);
        }
    }

    private function ajax(): bool { return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'; }

    private function validatePayrollCsrf(): void
    {
        $raw = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($this->ajax() && (!is_string($raw) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $raw))) {
            $message = 'Sesi formulir berakhir. Muat ulang halaman lalu coba kembali.';
            json_response(['saved' => false, 'error' => $message, 'issues' => [['code' => 'csrf_expired', 'severity' => 'error', 'field' => '_csrf', 'message' => $message]]], 419);
        }
        $this->validateCsrf();
    }

    private function formFailure(PayrollValidationException $exception, int $status): void
    {
        if ($this->ajax()) json_response(['saved' => false, 'error' => $exception->getMessage(), 'issues' => $exception->issues], $status);
        http_response_code($status); $this->view('error', ['errorMessage' => $exception->getMessage()], false);
    }
}