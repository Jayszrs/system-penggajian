<?php
declare(strict_types=1);
namespace App\Core;
abstract class Controller {
    protected function view(string $template, array $data = [], bool $layout = true): void {
        extract($data, EXTR_SKIP);
        if ($layout) { ob_start(); require BASE_PATH . '/app/Views/' . $template . '.php'; $content = ob_get_clean(); require BASE_PATH . '/app/Views/layout.php'; }
        else require BASE_PATH . '/app/Views/' . $template . '.php';
    }
    protected function validateCsrf(): void {
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) || empty($_SESSION['csrf'])) {
            if (str_starts_with(app_request_path(), '/api/')) json_response(['error'=>'Sesi formulir berakhir. Muat ulang halaman.'],419);
            http_response_code(419); $this->view('error', ['errorMessage'=>'Sesi formulir berakhir. Muat ulang halaman lalu coba lagi.'], false); exit;
        }
    }
    protected function audit(string $action, string $entity, string $key, mixed $before = null, mixed $after = null): void {
        $q = Database::connection()->prepare('INSERT INTO audit_log (user_id,action,entity,record_key,before_data,after_data) VALUES (?,?,?,?,?,?)');
        $q->execute([$_SESSION['user']['id'] ?? null,$action,$entity,$key,$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE)]);
    }
}
