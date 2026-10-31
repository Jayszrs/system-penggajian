<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Controller,Database};
final class AuthController extends Controller {
    public function setup(): void {
        $db=Database::connection();
        if ((int)$db->query('SELECT COUNT(*) FROM app_users')->fetchColumn()>0) redirect('/login');
        $error=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $this->validateCsrf(); $name=trim((string)($_POST['name']??'')); $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
            if ($name==='' || mb_strlen($name)>150 || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username)) $error='Isi nama dan ID pengguna (3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung).';
            elseif (strlen($password)<12) $error='Gunakan kata sandi minimal 12 karakter.';
            elseif ($password!==($_POST['password_confirmation']??'')) $error='Konfirmasi kata sandi belum sama.';
            else {
                $db->beginTransaction();
                $lock=$db->query("SELECT GET_LOCK('mh_payroll_first_user',5)")->fetchColumn();
                try {
                    if (!$lock || (int)$db->query('SELECT COUNT(*) FROM app_users')->fetchColumn()>0) { $db->rollBack(); redirect('/login'); }
                    $q=$db->prepare('INSERT INTO app_users(username,name,password_hash) VALUES(?,?,?)'); $q->execute([$username,$name,password_hash($password,PASSWORD_DEFAULT)]); $db->commit();
                    flash('success','Akun berhasil dibuat. Silakan masuk.'); redirect('/login');
                } finally { $db->query("SELECT RELEASE_LOCK('mh_payroll_first_user')"); }
            }
        }
        $this->view('auth',['setup'=>true,'error'=>$error,'title'=>'Mulai bersama Mutiara'],false);
    }
    public function login(): void {
        $db=Database::connection();
        if (!(int)$db->query('SELECT COUNT(*) FROM app_users')->fetchColumn()) redirect('/setup');
        if (!empty($_SESSION['user'])) redirect('/');
        $error=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $this->validateCsrf(); $username=trim((string)($_POST['username']??''));
            $fingerprint=hash('sha256',($_SERVER['REMOTE_ADDR']??'local').'|'.mb_strtolower($username));
            $q=$db->prepare('SELECT * FROM login_attempts WHERE fingerprint=?'); $q->execute([$fingerprint]); $attempt=$q->fetch();
            if ($attempt && (int)$attempt['failures']>=8 && strtotime($attempt['last_attempt'])>time()-900) $error='Terlalu banyak percobaan masuk. Coba kembali dalam 15 menit.';
            else {
                $q=$db->prepare('SELECT * FROM app_users WHERE username=?'); $q->execute([$username]); $user=$q->fetch();
                if ($user && password_verify((string)($_POST['password']??''),$user['password_hash'])) {
                    session_regenerate_id(true); $_SESSION['user']=['id'=>$user['id'],'username'=>$user['username'],'name'=>$user['name'],'session_version'=>$user['session_version']]; $_SESSION['csrf']=bin2hex(random_bytes(32));
                    $db->prepare('DELETE FROM login_attempts WHERE fingerprint=?')->execute([$fingerprint]); $this->audit('login','app_users',(string)$user['id']); redirect('/');
                }
                $q=$db->prepare('INSERT INTO login_attempts(fingerprint,failures,last_attempt) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=IF(last_attempt<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,failures+1),last_attempt=NOW()'); $q->execute([$fingerprint]);
                $error='ID pengguna atau kata sandi tidak sesuai.';
            }
        }
        $this->view('auth',['setup'=>false,'error'=>$error,'title'=>'Selamat datang kembali'],false);
    }
    public function logout(): void { $this->validateCsrf(); $_SESSION=[]; session_destroy(); redirect('/login'); }
    public function profile(): void {
        $error=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $this->validateCsrf(); $db=Database::connection(); $q=$db->prepare('SELECT * FROM app_users WHERE id=?'); $q->execute([$_SESSION['user']['id']]); $user=$q->fetch();
            $name=trim((string)($_POST['name']??'')); $new=(string)($_POST['new_password']??'');
            if (!$user || !password_verify((string)($_POST['current_password']??''),$user['password_hash'])) $error='Kata sandi saat ini tidak sesuai.';
            elseif ($name==='' || mb_strlen($name)>150) $error='Nama wajib diisi, maksimal 150 karakter.';
            elseif ($new!=='' && (strlen($new)<12 || $new!==($_POST['password_confirmation']??''))) $error='Kata sandi baru minimal 12 karakter dan konfirmasi harus sama.';
            else {
                $version=(int)$user['session_version']+($new!==''?1:0);
                $db->prepare('UPDATE app_users SET name=?,password_hash=?,session_version=? WHERE id=?')->execute([$name,$new!==''?password_hash($new,PASSWORD_DEFAULT):$user['password_hash'],$version,$user['id']]);
                $_SESSION['user']['name']=$name; $_SESSION['user']['session_version']=$version; session_regenerate_id(true); $this->audit('update','app_users',(string)$user['id']); flash('success','Profil berhasil diperbarui.'); redirect('/profil');
            }
        }
        $this->view('profile',['title'=>'Profil Saya','section'=>'Akun','error'=>$error]);
    }
}
