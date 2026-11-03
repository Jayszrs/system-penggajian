<?php
declare(strict_types=1);
namespace App\Services;

use App\Domain\{LegacyMapping, MasterModules, PayrollFields};
use PDO;

/** Read-only evidence for the migration page; never runs a migration on a web request. */
final class LegacyMigrationTrace
{
    private array $tables = [];
    private array $targets = [];
    private array $ledger = [];
    private array $runs = [];
    private array $audit = [];
    private array $specifications = [];

    public function __construct(private readonly PDO $db)
    {
        $this->specifications = LegacyMapping::tables();
        $this->runs = $db->query('SELECT * FROM migration_runs ORDER BY id DESC LIMIT 10')->fetchAll();
        $hash = $this->runs[0]['source_sha256'] ?? '';
        if ($hash !== '') {
            $q = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
            $q->execute(['legacy_migration_state:'.$hash]);
            $this->ledger = json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: [];
        }
        foreach ($db->query('SELECT entity,record_key,action FROM audit_log ORDER BY id') as $audit) {
            $this->audit[$audit['entity']][(string)$audit['record_key']] = strtolower((string)$audit['action']);
        }
        foreach ($db->query('SELECT * FROM legacy_tables ORDER BY table_name') as $table) {
            $name = strtolower($table['table_name']);
            $schema = json_decode($table['schema_data'], true) ?: [];
            $table['columns'] = [];
            foreach ($schema['columns'] ?? [] as $column) $table['columns'][strtolower($column['name'])] = $column;
            $table['specification'] = $this->specifications[$name] ?? null;
            $table['records'] = [];
            $this->tables[$name] = $table;
        }
        foreach ($db->query('SELECT table_name,source_ordinal,payload,row_sha256 FROM legacy_records ORDER BY table_name,source_ordinal') as $record) {
            $name = strtolower($record['table_name']);
            if (!isset($this->tables[$name])) continue;
            $decoded=json_decode($record['payload'],true);
            $record['archive_valid']=is_array($decoded) && hash_equals($record['row_sha256'],hash('sha256',$record['payload']));
            $record['canonical_digest']=hash('sha256',LegacyMigration::canonical($decoded));
            $record['values'] = array_change_key_case(is_array($decoded)?$decoded:[], CASE_LOWER);
            $this->tables[$name]['records'][] = $record;
        }
    }

    public function overview(): array
    {
        $tables = []; $totals = ['source_rows'=>0,'mapped'=>0,'preserved'=>0,'problems'=>0,'archive_only'=>0];
        $summary=json_decode((string)($this->runs[0]['summary'] ?? '{}'),true) ?: [];
        $exceptions=[];
        foreach([...($summary['exceptions'] ?? []),...($this->ledger['exceptions'] ?? [])] as $issue) {
            if(!is_array($issue))continue;
            $key=LegacyMigration::canonical([$issue['code'] ?? '',strtolower((string)($issue['table'] ?? '')),$issue['message'] ?? '']);
            // Prefer the run entry, which retains the archive row ordinal.
            $exceptions[$key]??=$issue;
        }
        foreach($this->specifications as $source=>$spec)if($spec['target']!==null && !isset($this->tables[$source])) {
            $present=false;
            foreach($exceptions as $issue)if(($issue['code'] ?? '')==='missing_source_table' && strtolower((string)($issue['table'] ?? ''))===$source){$present=true;break;}
            if(!$present)$exceptions[]=['code'=>'missing_source_table','table'=>$source,'message'=>'Tabel operasional tidak tersedia pada inventaris sumber; kelengkapan migrasi belum dapat diverifikasi.'];
        }
        $unrepresentedIssues=array_values(array_filter($exceptions,fn($issue)=>!isset($this->tables[strtolower((string)($issue['table'] ?? ''))])));
        foreach ($this->tables as $name => $table) {
            $spec = $table['specification'];
            $target = $spec['target'] ?? null;
            $counts = ['mapped'=>0,'preserved'=>0,'problems'=>0,'archive_only'=>0];
            foreach ($table['records'] as $record) {
                $trace = $this->trace($name, $record);
                $bucket = match ($trace['status']) {
                    'mapped'=>'mapped', 'local_changed','local_deleted'=>'preserved', 'archive_only'=>'archive_only', default=>'problems'
                };
                $counts[$bucket]++;
            }
            $sourceRows = count($table['records']);
            $orderedDigests=array_column($table['records'],'row_sha256');$canonicalDigests=array_column($table['records'],'canonical_digest');sort($canonicalDigests,SORT_STRING);
            $archiveVerified=$sourceRows===(int)$table['row_count'] && !array_filter($table['records'],static fn($record)=>!$record['archive_valid']) &&
                (hash_equals($table['content_sha256'],hash('sha256',implode("\n",$orderedDigests))) || hash_equals($table['content_sha256'],hash('sha256',implode("\n",$canonicalDigests))));
            if (!$archiveVerified) $counts['problems']++;
            if (!$spec) $counts['problems']++;
            $issues=array_values(array_filter($exceptions,static fn($issue)=>strtolower((string)($issue['table'] ?? ''))===$name));
            if(!$archiveVerified)$issues[]=['code'=>'archive_integrity','message'=>'Jumlah baris atau checksum arsip berbeda. Jalankan pemeriksaan lokal terhadap backup sumber sebelum memakai arsip ini.'];
            if($spec){
                foreach(array_diff(array_keys($spec['columns']),array_keys($table['columns'])) as $column)$issues[]=['code'=>'missing_source_column','message'=>"Kolom wajib $column tidak tersedia pada struktur arsip sumber."];
                foreach(array_diff(array_keys($table['columns']),array_keys($spec['columns'])) as $column)$issues[]=['code'=>'unmapped_source_column','message'=>"Kolom sumber $column belum memiliki pemetaan yang terverifikasi."];
            }
            $blockingIssues=array_filter($issues,static fn($issue)=>!in_array($issue['code'] ?? '',['preserved_local_difference','preserved_deletion'],true));
            if(!$counts['problems'] && $blockingIssues)$counts['problems']=count($blockingIssues);
            $status = $counts['problems'] ? 'problems' : ($sourceRows === 0 ? 'source_empty' : ($target ? ($counts['preserved'] ? 'preserved' : 'mapped') : 'archive_only'));
            $tables[] = [...$table, 'records'=>[], 'target'=>$target, 'status'=>$status, 'counts'=>$counts,
                'column_count'=>count($table['columns']), 'reason'=>$spec['reason'] ?? 'Tabel belum memiliki pemetaan operasional; seluruh nilai dipertahankan pada arsip.',
                'runtime_note'=>$this->runtimeNote($target),'issues'=>$issues,'archive_verified'=>$archiveVerified];
            $totals['source_rows'] += $sourceRows;
            foreach ($counts as $key=>$count) $totals[$key] += $count;
        }
        if(($this->ledger['mapping_version'] ?? null)===LegacyMapping::VERSION && ($this->ledger['status'] ?? null)!=='verified' && !$totals['problems'] && !$totals['preserved'] && !$unrepresentedIssues) {
            $unrepresentedIssues[]=['code'=>'reconciliation_unverified','message'=>'Status rekonsiliasi belum terverifikasi. Jalankan kembali pemeriksaan lokal untuk memperbarui hasil pemeriksaan.'];
        }
        $totals['problems']+=count($unrepresentedIssues);
        $employees = [];
        foreach ($this->targets('karyawan') as $employee) {
            $employees[(string)$employee['nip']] = ['nip'=>$employee['nip'],'nama'=>$employee['nama'],'kd_bagian'=>$employee['kd_bagian'],'origin'=>'local'];
        }
        foreach ($this->tables['dbo.karyawan']['records'] ?? [] as $record) {
            $source = $this->normalized('dbo.karyawan', $record['values']);
            $nip = (string)($source['nip'] ?? '');
            if ($nip === '') continue;
            $employees[$nip] = ['nip'=>$nip,'nama'=>$source['nama'] ?? $nip,'kd_bagian'=>$source['kd_bagian'] ?? '', 'origin'=>'source'];
        }
        $departments = [];
        foreach ($this->targets('bagian') as $department) $departments[(string)$department['kd_bagian']] = $department['nama_bagian'];
        foreach ($employees as &$employee) $employee['nama_bagian'] = $departments[(string)$employee['kd_bagian']] ?? $employee['kd_bagian'];
        unset($employee);
        uasort($employees, static fn($a,$b)=>strnatcasecmp((string)$a['nama'],(string)$b['nama']) ?: strcmp((string)$a['nip'],(string)$b['nip']));
        $reconciled=($this->ledger['mapping_version'] ?? null)===LegacyMapping::VERSION;
        return ['tables'=>$tables,'totals'=>$totals,'employees'=>array_values($employees),'runs'=>$this->runs,
            'mapping_version'=>$this->ledger['mapping_version'] ?? null,'source_sha256'=>$this->runs[0]['source_sha256'] ?? null,
            'unrepresented_issues'=>$unrepresentedIssues,'reconciled'=>$reconciled,
            'complete'=>$totals['problems']===0 && $totals['preserved']===0 && $reconciled && ($this->ledger['status'] ?? null)==='verified'];
    }

    public function employee(string $nip): ?array
    {
        $sourceEmployees = $this->matching('dbo.karyawan', 'nip', $nip);
        $current = array_values(array_filter($this->targets('karyawan'), static fn($row)=>(string)$row['nip'] === $nip));
        if (!$sourceEmployees && !$current) return null;
        $employee = $sourceEmployees[0] ?? null;
        $source = $employee ? $this->normalized('dbo.karyawan',$employee['values']) : [];
        $department = (string)($source['kd_bagian'] ?? $current[0]['kd_bagian'] ?? '');
        $master = [];
        foreach (['dbo.bagian','dbo.karyawan','dbo.tunj_harian','dbo.tunj_konsumsi','dbo.tunj_lembur','dbo.tunj_infal','dbo.tunj_overtime','dbo.tunj_jam','dbo.p_anak','dbo.p_bjbs','dbo.p_lunak','dbo.p_bank','dbo.p_lain','dbo.tabungan'] as $name) {
            $table = $this->tables[$name] ?? null;
            $target = $table['specification']['target'] ?? substr($name,4);
            $key = in_array($target,['bagian','tunj_harian','tunj_konsumsi','tunj_lembur','tunj_infal','tunj_overtime'],true) ? 'kd_bagian' : 'nip';
            $value = $key === 'nip' ? $nip : $department;
            $rows = $this->matching($name,$key,$value);
            $traces = array_map(fn($record)=>$this->trace($name,$record),$rows);
            $local = array_values(array_filter($this->targets($target),static fn($row)=>(string)($row[$key] ?? '')===$value));
            $title = MasterModules::all()[$target]['title'] ?? ($target === 'tabungan' ? 'Tabungan' : $target);
            $status=$table === null ? 'source_absent' : ((int)$table['row_count'] === 0 ? 'source_empty' : ($rows ? 'available' : 'no_employee_row'));
            $states=array_column($traces,'status');
            if($states)$status=array_intersect($states,['problems','conflict','missing','ambiguous'])?'problems':(in_array('local_deleted',$states,true)?'local_deleted':(in_array('local_changed',$states,true)?'local_changed':'mapped'));
            $master[] = ['name'=>$table['table_name'] ?? $name,'title'=>$title,'target'=>$target,'key'=>$key,'key_value'=>$value,'traces'=>$traces,'local_rows'=>$local,
                'status'=>$status,
                'runtime_note'=>$this->runtimeNote($target), 'archive_url'=>$table ? $this->archiveUrl($table['table_name']) : null,
                'target_url'=>$this->targetUrl($target,$local[0] ?? [],$value)];
        }
        $history = [];
        foreach ($this->matching('dbo.penghasilan','nip',$nip) as $record) {
            $trace = $this->trace('dbo.penghasilan',$record);
            $values = $this->normalized('dbo.penghasilan',$record['values']);
            $trace['period'] = ['bulan'=>$values['bulan'] ?? '', 'tahun'=>$values['tahun'] ?? ''];
            // Always show all payroll components, including absent, NULL and zero values.
            $components = [];
            foreach (PayrollFields::numeric() as $field=>$label) {
                $components[] = $trace['fields'][$field] ?? ['source_column'=>$field,'target_column'=>$field,'label'=>$label,'source'=>null,'current'=>$trace['current'][$field] ?? null,'status'=>'source_absent'];
            }
            $trace['components'] = $components;
            $history[] = $trace;
        }
        usort($history,static fn($a,$b)=>[(int)$b['period']['tahun'],(int)$b['period']['bulan'],$b['ordinal']] <=> [(int)$a['period']['tahun'],(int)$a['period']['bulan'],$a['ordinal']]);
        foreach ($master as &$item) {
            $field = match($item['target']) {
                'tunj_harian'=>'t_harian','tunj_konsumsi'=>'t_konsumsi','tunj_lembur'=>'ulembur','tunj_infal'=>'uinfal','tunj_overtime'=>'uovertime','tunj_jam'=>'kelebihan',
                'p_anak'=>'p_anak','p_bjbs'=>'pinjaman_bsm','p_lunak'=>'pinjaman_lunak','p_bank'=>'arisan','p_lain'=>'plain','tabungan'=>'tabungan',default=>null
            };
            $item['history_field']=$field;$item['history_rows']=0;$item['history_nonzero']=0;
            if($field)foreach($history as $transaction)if(array_key_exists($field,$transaction['source'])) {
                $item['history_rows']++;
                $amount=$transaction['source'][$field];
                if($amount!==null && bccomp((string)$amount,'0',4)!==0)$item['history_nonzero']++;
            }
        }
        unset($item);
        $references=[];
        foreach (['dbo.pgw$','dbo.sheet1$'] as $name) foreach ($this->matching($name,'nip',$nip) as $record) $references[]=$this->trace($name,$record);
        return ['nip'=>$nip,'name'=>$source['nama'] ?? $current[0]['nama'] ?? $nip,'department'=>$department,'source_employees'=>count($sourceEmployees),'current_employee'=>$current[0] ?? null,
            'identity'=>$employee ? $this->trace('dbo.karyawan',$employee) : null,'masters'=>$master,'history'=>$history,'references'=>$references,'source_only'=>!$current];
    }

    public static function statusLabel(string $status): string
    {
        return match($status) {
            'mapped'=>'Berhasil dipetakan','source_empty'=>'Kosong pada sumber','archive_only'=>'Hanya arsip','problems'=>'Bermasalah',
            'preserved'=>'Perubahan lokal dipertahankan','local_changed'=>'Perubahan lokal dipertahankan','local_deleted'=>'Penghapusan lokal dipertahankan',
            'missing'=>'Belum tersedia di tujuan','ambiguous'=>'Identitas ambigu','conflict'=>'Nilai berbeda','source_absent'=>'Tidak ada pada sumber',
            'no_employee_row'=>'Tidak ada baris terkait','available'=>'Tersedia pada sumber','same'=>'Cocok','different'=>'Berbeda','target_absent'=>'Tujuan tidak tersedia',
            default=>$status
        };
    }

    public static function displayValue(mixed $value): string
    {
        if ($value === null) return 'NULL';
        if ($value === '') return '""';
        if (is_bool($value)) return $value ? 'true' : 'false';
        return (string)$value;
    }

    private function trace(string $name,array $record): array
    {
        $table = $this->tables[$name];$spec=$table['specification'];$target=$spec['target'] ?? null;
        $source=$this->normalized($name,$record['values']);$current=null;$status='archive_only';$ledger=null;
        if ($target) {
            $candidates=[];$key=$spec['key'] ?? null;
            foreach ($this->targets($target) as $row) {
                $keys=is_array($key)?$key:($key ? [$key] : ($target==='penghasilan'?['nip','bulan','tahun']:[]));
                if (!$keys) continue;
                $matches=true;
                foreach($keys as $field) {
                    $a=$source[$field] ?? null;$b=$row[$field] ?? null;
                    if(in_array($field,['bulan','tahun'],true) && $a!==null && $b!==null) {$a=(string)(int)$a;$b=(string)(int)$b;}
                    if($a!==$b && (string)$a!==(string)$b){$matches=false;break;}
                }
                if($matches)$candidates[]=$row;
            }
            $ledger=$this->ledgerRow($name,$record,$source);
            if(isset($ledger['target_id']))foreach($this->targets($target) as $row)if((string)$row['id']===(string)$ledger['target_id']){$current=$row;break;}
            if(!$ledger && count($candidates)===1)$current=$candidates[0];
            if($current) {
                $status=$this->sameMapped($name,$source,$current)?'mapped':($ledger && $this->locallyChanged($name,$current,$ledger)?'local_changed':'conflict');
                if(($ledger['outcome'] ?? '')==='exception')$status='problems';
            } else {
                $deleted=in_array($ledger['outcome'] ?? '',['local_deleted','preserved_deletion'],true) || ($ledger && ($this->audit[$target][(string)($ledger['target_id'] ?? '')] ?? '')==='delete');
                if(!$deleted && $key && !is_array($key))$deleted=($this->audit[$target][(string)($source[$key] ?? '')] ?? '')==='delete';
                $status=$deleted?'local_deleted':(count($candidates)>1?'ambiguous':'missing');
            }
        }
        if(!$record['archive_valid'])$status='problems';
        $fields=[];
        foreach($table['columns'] as $field=>$column) {
            $mapped=$this->mappedColumn($spec,$field);$value=$record['values'][$field] ?? null;
            $actual=$mapped!==null && $current!==null && array_key_exists($mapped,$current) ? $current[$mapped] : null;
            $fieldStatus=!$target?'archive_only':($mapped===null?'source_absent':($current===null || !array_key_exists($mapped,$current)?'target_absent':($this->sameValue($source[$field] ?? null,$actual,$column)?'same':'different')));
            $fields[$field]=['source_column'=>$field,'target_column'=>$mapped,'label'=>PayrollFields::numeric()[$field] ?? MasterModules::all()[$target ?? '']['fields'][$field] ?? $column['name'],'source'=>$value,'current'=>$actual,'status'=>$fieldStatus];
        }
        return ['table'=>$table['table_name'],'target'=>$target,'ordinal'=>(int)$record['source_ordinal'],'status'=>$status,'ledger_outcome'=>$ledger['outcome'] ?? null,
            'source'=>$source,'current'=>$current,'fields'=>$fields,'archive_url'=>$this->archiveUrl($table['table_name'],(int)$record['source_ordinal']),
            'target_url'=>$target?$this->targetUrl($target,$current ?? [],(string)($source['nip'] ?? $source['kd_bagian'] ?? '')):null];
    }

    private function targets(string $target): array
    {
        if(isset($this->targets[$target]))return $this->targets[$target];
        $allowed=[...array_keys(MasterModules::all()),'penghasilan','tabungan'];
        if(!in_array($target,$allowed,true))return [];
        return $this->targets[$target]=$this->db->query("SELECT * FROM `$target` ORDER BY id")->fetchAll();
    }

    private function matching(string $name,string $field,string $value): array
    {
        return array_values(array_filter($this->tables[$name]['records'] ?? [],fn($record)=>(string)($this->normalized($name,$record['values'])[$field] ?? '')===$value));
    }

    private function normalized(string $name,array $row): array
    {
        foreach($this->tables[$name]['columns'] ?? [] as $field=>$column) {
            if(!array_key_exists($field,$row) || $row[$field]===null)continue;
            if(in_array(strtolower((string)$column['sql_type']),['char','nchar'],true))$row[$field]=rtrim((string)$row[$field],' ');
            elseif(in_array(strtolower((string)$column['sql_type']),['numeric','decimal','money','smallmoney'],true))$row[$field]=bcadd((string)$row[$field],'0',(int)($column['scale'] ?? 0));
        }
        return $row;
    }

    private function mappedColumn(?array $spec,string $field): ?string
    {
        if(!$spec || empty($spec['target']))return null;
        $columns=$spec['columns'] ?? [];
        if(array_key_exists($field,$columns))return is_string($columns[$field])?$columns[$field]:($columns[$field]['target'] ?? null);
        if(in_array($field,$columns,true))return $field;
        return null;
    }

    private function sameMapped(string $name,array $source,array $current): bool
    {
        $table=$this->tables[$name];
        foreach($table['columns'] as $field=>$column) {
            $mapped=$this->mappedColumn($table['specification'],$field);
            if($mapped===null || !array_key_exists($mapped,$current) || !$this->sameValue($source[$field] ?? null,$current[$mapped],$column))return false;
        }
        return true;
    }

    private function sameValue(mixed $source,mixed $current,array $column): bool
    {
        if($source===null || $current===null)return $source===$current;
        if(in_array(strtolower((string)$column['sql_type']),['numeric','decimal','money','smallmoney','int','smallint','bigint','tinyint','bit'],true))return bccomp((string)$source,(string)$current,(int)($column['scale'] ?? 0))===0;
        return (string)$source===(string)$current;
    }

    private function ledgerRow(string $name,array $record,array $source): ?array
    {
        $spec=$this->tables[$name]['specification'];$mapped=[];
        foreach($source as $field=>$value)if(($target=$this->mappedColumn($spec,$field))!==null)$mapped[$target]=$value;
        $parts=[];
        foreach($spec['key'] ?? [] as $field) {
            $value=$mapped[$field] ?? null;
            if($value!==null && in_array($field,['bulan','tahun'],true))$value=(string)(int)$value;
            $parts[$field]=$value;
        }
        $identity=isset($mapped['legacy_id'])?'id:'.$mapped['legacy_id']:'key:'.LegacyMigration::canonical($parts);
        $state=$this->ledger['rows'][$name.':'.hash('sha256',$identity)] ?? null;
        return is_array($state)?$state:null;
    }

    private function locallyChanged(string $name,array $current,array $ledger): bool
    {
        $spec=$this->tables[$name]['specification'];$target=$spec['target'];$mapped=[];
        foreach($this->tables[$name]['columns'] as $field=>$column)if(($targetField=$this->mappedColumn($spec,$field))!==null && array_key_exists($targetField,$current)) {
            $value=$current[$targetField];
            if($value!==null && in_array(strtolower((string)$column['sql_type']),['numeric','decimal','money','smallmoney'],true))$value=bcadd((string)$value,'0',(int)($column['scale'] ?? 0));
            $mapped[$targetField]=$value;
        }
        $baseline=$ledger['target_baseline'] ?? null;
        if(is_string($baseline) && !hash_equals($baseline,hash('sha256',LegacyMigration::canonical($mapped))))return true;
        if(is_array($baseline)) {
            foreach($baseline as $field=>$value)if(array_key_exists($field,$current) && ($value===null ? $current[$field]!==null : (string)$value!==(string)$current[$field]))return true;
        }
        return in_array($ledger['outcome'] ?? '',['local_changed','preserved_local','preserved_local_change'],true) || in_array($this->audit[$target][(string)$current['id']] ?? '',['update','edit'],true);
    }

    private function archiveUrl(string $table,int $ordinal=1): string
    {
        return url('arsip',['table'=>$table,'page'=>(int)ceil(max(1,$ordinal)/25)]);
    }

    private function targetUrl(string $target,array $row,string $key): ?string
    {
        if($target==='penghasilan')return !empty($row['id'])?url('transaksi/form',['id'=>$row['id']]):null;
        if(!isset(MasterModules::all()[$target]))return null;
        return url('master/'.$target,!empty($row['id'])?['edit'=>$row['id']]:['q'=>$key]);
    }

    private function runtimeNote(?string $target): string
    {
        return match($target) {
            'tunj_harian'=>'Tarif harian untuk perhitungan aplikasi berasal dari karyawan.t_harian; tabel ini tetap dipertahankan sesuai sumber.',
            'tunj_lembur'=>'Tarif lembur untuk perhitungan aplikasi berasal dari karyawan.t_lembur; tabel ini tetap dipertahankan sesuai sumber.',
            'tabungan'=>'Nilai tabungan historis berada pada transaksi penghasilan. Tabel sumber dipertahankan; perhitungan otomatis saat ini tidak mengambil nilai dari tabel ini.',
            'penghasilan'=>'Seluruh komponen transaksi dipertahankan sebagai nilai historis, tanpa dihitung ulang.',
            default=>''
        };
    }
}
