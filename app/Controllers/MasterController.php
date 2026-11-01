<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Controller,Database};
use App\Domain\MasterModules;
use App\Services\PayrollRepository;
final class MasterController extends Controller {
    public function index(string $module): void {
        $meta=MasterModules::get($module);$table=$meta['table'];$db=Database::connection();$error=null;$edit=null;
        if($_SERVER['REQUEST_METHOD']==='POST') {
            $this->validateCsrf();$id=max(0,(int)($_POST['id']??0));$action=$_POST['action']??'save';
            try {
                $db->beginTransaction();
                if($action==='delete') {
                    $before=$this->find($meta,$id);if(!$before)throw new \InvalidArgumentException('Data tidak ditemukan.');
                    if($module==='bagian') {
                        foreach(['karyawan','tunj_harian','tunj_konsumsi','tunj_lembur','tunj_infal','tunj_overtime'] as $t){$q=$db->prepare("SELECT COUNT(*) FROM `$t` WHERE kd_bagian=?");$q->execute([$before['kd_bagian']]);if($q->fetchColumn())throw new \InvalidArgumentException('Bagian masih digunakan. Perbarui data terkait sebelum menghapus.');}
                    } elseif($module==='karyawan') {
                        foreach(['penghasilan','tunj_jam','p_anak','p_bjbs','p_bank','p_lunak','p_lain','tabungan'] as $t){$q=$db->prepare("SELECT COUNT(*) FROM `$t` WHERE nip=?");$q->execute([$before['nip']]);if($q->fetchColumn())throw new \InvalidArgumentException('Karyawan masih memiliki transaksi atau komponen gaji. Data tidak dihapus.');}
                    }
                    $db->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);$this->audit('delete',$module,(string)$id,$before);$db->commit();flash('success','Data berhasil dihapus.');redirect('/master/'.$module);
                }
                $data=[];
                foreach($meta['fields'] as $field=>$label) {
                    $v=trim((string)($_POST[$field]??''));
                    if(MasterModules::monetary($field)) {if(!preg_match('/^\d{1,18}$/',$v))throw new \InvalidArgumentException("$label harus berupa bilangan bulat positif atau nol, maksimal 18 digit.");$v=bcadd($v,'0',0);}
                    else {if($v==='')throw new \InvalidArgumentException("$label wajib diisi.");$max=match($field){'nip'=>15,'kd_bagian'=>5,'nama'=>150,default=>100};if(mb_strlen($v)>$max)throw new \InvalidArgumentException("$label maksimal $max karakter.");}
                    $data[$field]=$v;
                }
                if($module!=='bagian' && isset($data['kd_bagian'])) {$q=$db->prepare('SELECT COUNT(*) FROM bagian WHERE kd_bagian=?');$q->execute([$data['kd_bagian']]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Bagian tidak ditemukan.');}
                if($module!=='karyawan' && isset($data['nip'])) {$q=$db->prepare('SELECT COUNT(*) FROM karyawan WHERE nip=?');$q->execute([$data['nip']]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Karyawan tidak ditemukan.');}
                $columns=array_keys($meta['fields']);$set=implode(',',array_map(static fn($f)=>"`$f`=?",$columns));$cols=implode('`,`',$columns);$marks=implode(',',array_fill(0,count($columns),'?'));
                if($id){$before=$this->find($meta,$id);if(!$before)throw new \InvalidArgumentException('Data tidak ditemukan.');if($before[$meta['key']]!==$data[$meta['key']])throw new \InvalidArgumentException('Identitas data tidak dapat diganti saat mengubah data.');$q=$db->prepare("UPDATE `$table` SET $set WHERE id=?");$q->execute([...array_values($data),$id]);}
                else {$before=null;$q=$db->prepare("INSERT INTO `$table` (`$cols`) VALUES($marks)");$q->execute(array_values($data));$id=(int)$db->lastInsertId();}
                $this->audit($before?'update':'create',$module,(string)$id,$before,$data);$db->commit();flash('success','Data berhasil disimpan.');redirect('/master/'.$module);
            } catch(\InvalidArgumentException|\PDOException $e){if($db->inTransaction())$db->rollBack();$error=$e instanceof \PDOException?($e->getCode()==='23000'?'Kode atau NIP sudah digunakan pada modul ini.':'Data belum berhasil disimpan. Periksa isian lalu coba kembali.'):$e->getMessage();$edit=['id'=>$id,...$_POST];}
        }
        $editId=filter_var($_GET['edit']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$edit && $editId)$edit=$this->find($meta,$editId);
        $search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$offset=($page-1)*25;
        $join=$module==='karyawan'?' LEFT JOIN bagian b ON b.kd_bagian=m.kd_bagian':($module==='bagian'?'':($meta['key']==='nip'?' LEFT JOIN karyawan k ON k.nip=m.nip':' LEFT JOIN bagian b ON b.kd_bagian=m.kd_bagian'));
        $extra=$module==='bagian'?'':($meta['key']==='nip' && $module!=='karyawan'?',k.nama AS reference_name':',b.nama_bagian AS reference_name');
        $searchCols=['m.'.$meta['key']];if($module==='bagian')$searchCols[]='m.nama_bagian';elseif($module==='karyawan')$searchCols[]='m.nama';else $searchCols[]=($meta['key']==='nip'?'k.nama':'b.nama_bagian');
        $where=$search!==''?' WHERE ('.implode(' OR ',array_map(static fn($c)=>"$c LIKE ?",$searchCols)).')':'';$params=$search!==''?array_fill(0,count($searchCols),'%'.$search.'%'):[];
        $q=$db->prepare("SELECT COUNT(*) FROM `$table` m $join $where");$q->execute($params);$total=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT m.* $extra FROM `$table` m $join $where ORDER BY m.`{$meta['key']}` LIMIT 25 OFFSET $offset");$q->execute($params);
        $suggestionName=match(true){$module==='bagian'=>'m.nama_bagian',$module==='karyawan'=>'m.nama',$meta['key']==='nip'=>'k.nama',default=>'b.nama_bagian'};
        $suggestionJoin=$join;$suggestionDepartment='';
        if($meta['key']==='nip'){
            $employeeAlias=$module==='karyawan'?'m':'k';$departmentAlias=$module==='karyawan'?'b':'employee_bagian';
            if($module!=='karyawan')$suggestionJoin.=' LEFT JOIN bagian employee_bagian ON employee_bagian.kd_bagian=k.kd_bagian';
            $suggestionDepartment=",$employeeAlias.kd_bagian,$departmentAlias.nama_bagian";
        }
        $searchOptions=$db->query("SELECT m.`{$meta['key']}` AS code,$suggestionName AS name $suggestionDepartment FROM `$table` m $suggestionJoin ORDER BY $suggestionName,m.`{$meta['key']}`")->fetchAll();
        $this->view('master',['title'=>$meta['title'],'section'=>ucfirst($meta['group']),'module'=>$module,'meta'=>$meta,'rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'search'=>$search,'edit'=>$edit,'error'=>$error,'searchOptions'=>$searchOptions,'showForm'=>$edit || isset($_GET['new']) || $error,'departments'=>$db->query('SELECT kd_bagian,nama_bagian FROM bagian ORDER BY kd_bagian')->fetchAll(),'employees'=>(new PayrollRepository())->employeeOptions()]);
    }
    private function find(array $meta,int $id): array|false {if(!isset($meta['table']))throw new \InvalidArgumentException('Modul tidak valid.');$table=$meta['table'];$q=Database::connection()->prepare("SELECT * FROM `$table` WHERE id=? FOR UPDATE");$q->execute([$id]);return $q->fetch();}
}
