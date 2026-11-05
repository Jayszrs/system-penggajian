<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});
if (is_file(BASE_PATH . '/.env')) {
    foreach (file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (!array_key_exists(trim($key), $_ENV)) $_ENV[trim($key)] = trim(trim($value), "\"'");
    }
}
require __DIR__ . '/helpers.php';
ini_set('display_errors', ($_ENV['APP_DEBUG'] ?? '') === 'true' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/app.log');
set_exception_handler(static function (Throwable $e): void {
    if($e instanceof App\Domain\PayrollValidationException){
        http_response_code(422);
        if(expects_json())json_response(['error'=>$e->getMessage(),'issues'=>$e->issues],422);
        $errorMessage=$e->getMessage();$feedback=['severity'=>'error','title'=>'Periksa data yang diminta','message'=>$errorMessage,'issues'=>$e->issues];
        require BASE_PATH.'/app/Views/error.php';return;
    }
    $id = bin2hex(random_bytes(4));
    error_log(date('c') . " [$id] " . $e . PHP_EOL, 3, BASE_PATH . '/storage/app.log');
    http_response_code(500);
    if (expects_json()) {
        header('Content-Type: application/json'); echo json_encode(['error'=>'Permintaan belum berhasil. Silakan coba kembali.','reference'=>$id]); return;
    }
    $errorMessage = 'Aplikasi belum dapat memproses permintaan. Periksa koneksi database atau hubungi pengelola.';
    if (($_ENV['APP_DEBUG'] ?? '') === 'true') $errorMessage .= ' ' . $e->getMessage();
    require BASE_PATH . '/app/Views/error.php';
});
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    session_name('mh_payroll');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','path'=>app_base_path().'/']);
    session_start();
}
