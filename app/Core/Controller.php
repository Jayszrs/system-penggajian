<?php
declare(strict_types=1);
namespace App\Core;
abstract class Controller {
    protected function view(string $template, array $data = [], bool $layout = true): void {
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && expects_json() && !empty($data['error'])){
            $status=http_response_code();$status=$status>=400?$status:422;
            json_response(['error'=>$data['error'],'issues'=>$data['issues']??[['code'=>$status>=500?'server_error':'validation_failed','severity'=>'error','field'=>'','message'=>$data['error']]]],$status);
        }
        extract($data, EXTR_SKIP);
        if ($layout) { ob_start(); require BASE_PATH . '/app/Views/' . $template . '.php'; $content = ob_get_clean(); require BASE_PATH . '/app/Views/layout.php'; }
        else require BASE_PATH . '/app/Views/' . $template . '.php';
    }
    protected function validateCsrf(): void {
        $submitted=$_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??'';
        if (!is_string($submitted) || !hash_equals($_SESSION['csrf'] ?? '', $submitted) || empty($_SESSION['csrf'])) {
            if (expects_json()) json_response(['error'=>'Sesi formulir berakhir. Muat ulang halaman.','issues'=>[['code'=>'csrf_expired','severity'=>'error','field'=>'','message'=>'Sesi formulir berakhir. Muat ulang halaman sebelum mencoba lagi.']]],419);
            http_response_code(419); $this->view('error', ['errorMessage'=>'Sesi formulir berakhir. Muat ulang halaman lalu coba lagi.'], false); exit;
        }
    }
    protected function audit(string $action, string $entity, string $key, mixed $before = null, mixed $after = null): void {
        $q = Database::connection()->prepare('INSERT INTO audit_log (user_id,action,entity,record_key,before_data,after_data) VALUES (?,?,?,?,?,?)');
        $q->execute([$_SESSION['user']['id'] ?? null,$action,$entity,$key,$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE)]);
    }
}
