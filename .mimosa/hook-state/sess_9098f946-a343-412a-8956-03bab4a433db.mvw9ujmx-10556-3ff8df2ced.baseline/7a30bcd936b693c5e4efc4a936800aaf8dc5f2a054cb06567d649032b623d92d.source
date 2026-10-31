<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Controller,Database};
use App\Services\{PayrollRepository,PayrollCalculator};
use App\Domain\PayrollFields;
use InvalidArgumentException;
final class PayrollController extends Controller {
    public function index(): void {
        $db=Database::connection();$repo=new PayrollRepository();$period=$repo->period();$error=null;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $this->validateCsrf();$id=(int)($_POST['id']??0);$db->beginTransaction();
            try{$q=$db->prepare('SELECT * FROM penghasilan WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch();if(!$before)throw new InvalidArgumentException('Transaksi tidak ditemukan.');$db->prepare('DELETE FROM penghasilan WHERE id=?')->execute([$id]);$this->audit('delete','penghasilan',(string)$id,$before);$db->commit();flash('success','Transaksi berhasil dihapus.');redirect(url('transaksi',$period));}catch(InvalidArgumentException $e){$db->rollBack();$error=$e->getMessage();}
        }
        [$where,$params]=$repo::scope($period);$search=trim((string)($_GET['q']??''));if($search!==''){$where.=' AND (p.nip LIKE ? OR COALESCE(p.employee_name,k.nama) LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
        $join=' FROM penghasilan p LEFT JOIN karyawan k ON k.nip=p.nip LEFT JOIN bagian b ON b.kd_bagian=COALESCE(p.department_code,k.kd_bagian)';
        $q=$db->prepare("SELECT COUNT(*) AS n,COALESCE(SUM(p.tpenghasilan),0) AS gross,COALESCE(SUM(p.tpotongan),0) AS deductions,COALESCE(SUM(p.tbersih),0) AS net $join WHERE $where");$q->execute($params);$summary=$q->fetch();
        $page=max(1,(int)($_GET['page']??1));$offset=($page-1)*25;$q=$db->prepare("SELECT p.*,COALESCE(p.employee_name,k.nama,p.nip) AS nama,COALESCE(p.department_name,b.nama_bagian,'—') AS nama_bagian $join WHERE $where ORDER BY p.nip,p.id LIMIT 25 OFFSET $offset");$q->execute($params);
        $this->view('payroll-list',['title'=>'Transaksi Penghasilan','section'=>'Transaksi','period'=>$period,'departments'=>$repo->departments(),'rows'=>$q->fetchAll(),'page'=>$page,'search'=>$search,'summary'=>$summary,'error'=>$error]);
    }
    public function form(): void {
        $db=Database::connection();$repo=new PayrollRepository();$id=max(0,(int)($_POST['id']??$_GET['id']??0));$before=$id?$repo->find($id):false;
        if($id&&!$before){http_response_code(404);$this->view('error',['errorMessage'=>'Transaksi tidak ditemukan.'],false);return;}
        $period=$repo->period();$values=$before?:array_fill_keys(array_keys(PayrollFields::numeric()),'0');$values['bulan']=$before['bulan']??$period['bulan'];$values['tahun']=$before['tahun']??$period['tahun'];$error=null;
        $mode=$before?'manual':'master';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $this->validateCsrf();$values=[...$values,...$_POST];$mode=$_POST['calculation_mode']??'manual';$lock=null;
            try{
                $nip=trim((string)($_POST['nip']??''));if($nip===''||strlen($nip)>15)throw new InvalidArgumentException('Pilih NIP karyawan yang valid, maksimal 15 karakter.');
                $month=filter_var($_POST['bulan']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]);$year=filter_var($_POST['tahun']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1900,'max_range'=>9999]]);if(!$month||!$year)throw new InvalidArgumentException('Bulan dan tahun harus valid.');
                if($before && ($before['nip']!==$nip||(int)$before['bulan']!==$month||(int)$before['tahun']!==$year))throw new InvalidArgumentException('NIP dan periode transaksi tersimpan tidak dapat diganti.');
                $q=$db->prepare('SELECT k.*,b.nama_bagian FROM karyawan k LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian WHERE k.nip=?');$q->execute([$nip]);$employee=$q->fetch();if(!$employee && !$before)throw new InvalidArgumentException('Karyawan tidak ditemukan pada master.');
                if($employee && ($_POST['bagian']??'')!==$employee['kd_bagian'] && !$before)throw new InvalidArgumentException('Bagian tidak sesuai dengan karyawan yang dipilih.');
                $numbers=(new PayrollCalculator())->fromInput($_POST,$mode==='master');
                $lock='mh-payroll-'.substr(hash('sha256',($_ENV['DB_DATABASE']??'penggajian').'|'.$nip.'|'.$year.'|'.$month),0,50);$q=$db->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new InvalidArgumentException('Transaksi sedang diproses. Silakan coba kembali.');
                $db->beginTransaction();$q=$db->prepare('SELECT id FROM penghasilan WHERE nip=? AND CAST(bulan AS UNSIGNED)=? AND CAST(tahun AS UNSIGNED)=? AND id<>? FOR UPDATE');$q->execute([$nip,$month,$year,$id]);if($q->fetchColumn())throw new InvalidArgumentException('NIP dan periode ini sudah memiliki transaksi. Buka transaksi tersimpan untuk mengubahnya.');
                $record=['nip'=>$nip,'bulan'=>$before?$before['bulan']:str_pad((string)$month,2,'0',STR_PAD_LEFT),'tahun'=>(string)$year,...$numbers,'source'=>$before['source']??($mode==='master'?'calculated':'manual'),'employee_name'=>$before['employee_name']??$employee['nama']??null,'department_code'=>$before['department_code']??$employee['kd_bagian']??null,'department_name'=>$before['department_name']??$employee['nama_bagian']??null];
                if($id){$q=$db->prepare('UPDATE penghasilan SET '.implode(',',array_map(static fn($f)=>"`$f`=?",array_keys($record))).' WHERE id=?');$q->execute([...array_values($record),$id]);}
                else{$q=$db->prepare('INSERT INTO penghasilan (`'.implode('`,`',array_keys($record)).'`) VALUES('.implode(',',array_fill(0,count($record),'?')).')');$q->execute(array_values($record));$id=(int)$db->lastInsertId();}
                $this->audit($before?'update':'create','penghasilan',(string)$id,$before?:null,$record);$db->commit();flash('success','Penghasilan berhasil disimpan.');redirect('/transaksi/form?id='.$id);
            }catch(InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
            finally{if($lock){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}}
        }
        $this->view('payroll-form',['title'=>$id?'Rincian Penghasilan':'Transaksi Baru','section'=>'Transaksi','values'=>$values,'id'=>$id,'error'=>$error,'mode'=>$mode,'departments'=>$repo->departments(),'employeeName'=>$before['nama']??$_POST['employee_name']??'','department'=>$before['kd_bagian']??$_POST['bagian']??$period['bagian'],'before'=>$before]);
    }
    public function employees(): void {
        $search=mb_substr(trim((string)($_GET['q']??'')),0,100);$department=trim((string)($_GET['bagian']??''));$where='(k.nip LIKE ? OR k.nama LIKE ?)';$params=['%'.$search.'%','%'.$search.'%'];if($department!==''){$where.=' AND k.kd_bagian=?';$params[]=$department;}
        $q=Database::connection()->prepare("SELECT k.nip,k.nama,k.kd_bagian,b.nama_bagian FROM karyawan k LEFT JOIN bagian b ON b.kd_bagian=k.kd_bagian WHERE $where ORDER BY k.nama LIMIT 100");$q->execute($params);json_response(['employees'=>$q->fetchAll()]);
    }
    public function departments(): void {json_response(['departments'=>(new PayrollRepository())->departments()]);}
    public function calculate(): void {
        $this->validateCsrf();
        try{$useDefaults=($_POST['load_defaults']??'')==='1'||($_POST['calculation_mode']??'')==='master';$values=(new PayrollCalculator())->fromInput($_POST,$useDefaults);json_response(['values'=>$values,'notice'=>$useDefaults?'Komponen dihitung menggunakan master saat ini. Periksa rincian sebelum menyimpan.':null]);}
        catch(InvalidArgumentException $e){json_response(['error'=>$e->getMessage()],422);}
    }
}
