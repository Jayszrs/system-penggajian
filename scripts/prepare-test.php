<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
// The test database is reserved by this repository. Never accept a dynamic name.
$_ENV['DB_DATABASE']='penggajian_test';
$server=App\Core\Database::connection(false);
$exists=$server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='penggajian_test' AND table_name='app_users'")->fetchColumn();
if($exists){$users=$server->query('SELECT username FROM penggajian_test.app_users')->fetchAll(PDO::FETCH_COLUMN);foreach($users as $user)if($user!=='qa_penggajian')throw new RuntimeException('Database uji berisi akun selain akun QA. Reset dibatalkan.');}
$server->exec('DROP DATABASE IF EXISTS penggajian_test');
require __DIR__.'/install.php';
// Import uses the restored SQL Server export, never generated demo payroll rows.
require __DIR__.'/migrate-legacy.php';
