<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
use App\Core\Database;
use App\Controllers\{AuthController,DashboardController,MasterController,PayrollController,ReportController};
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/');$path=rtrim($path,'/')?:'/';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
if($path==='/setup'){(new AuthController())->setup();exit;}
if($path==='/login'){(new AuthController())->login();exit;}
if(empty($_SESSION['user'])){if(str_starts_with($path,'/api/'))json_response(['error'=>'Silakan masuk kembali.'],401);redirect('/login');}
$q=Database::connection()->prepare('SELECT session_version FROM app_users WHERE id=?');$q->execute([$_SESSION['user']['id']]);$version=$q->fetchColumn();
if($version===false || (int)$version!==(int)$_SESSION['user']['session_version']){$_SESSION=[];redirect('/login');}
if($path==='/logout' && $method==='POST'){(new AuthController())->logout();exit;}
if($path==='/profil'){(new AuthController())->profile();exit;}
if($path==='/'){(new DashboardController())->index();exit;}
if($path==='/migrasi'){(new DashboardController())->migration();exit;}
if($path==='/arsip'){(new DashboardController())->archive();exit;}
if(preg_match('~^/master/([a-z_]+)$~',$path,$m) && isset(App\Domain\MasterModules::all()[$m[1]])){(new MasterController())->index($m[1]);exit;}
if($path==='/transaksi'){(new PayrollController())->index();exit;}
if($path==='/transaksi/form'){(new PayrollController())->form();exit;}
if($path==='/api/karyawan'){(new PayrollController())->employees();exit;}
if($path==='/api/bagian'){(new PayrollController())->departments();exit;}
if($path==='/api/hitung' && $method==='POST'){(new PayrollController())->calculate();exit;}
if($path==='/laporan'){(new ReportController())->index();exit;}
http_response_code(404);$errorMessage='Halaman yang Anda cari tidak ditemukan.';require __DIR__.'/app/Views/error.php';
