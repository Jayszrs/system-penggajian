<?php use App\Services\LegacyMigrationTrace; ?>
<div class="page-heading"><div><h1>Data &amp; Migrasi<span class="heading-dot">.</span></h1></div><span class="soft-badge"><?=count($tables)?> tabel terarsip</span></div>
<div class="migration-trace">
    <section class="surface migration-employee-search" aria-labelledby="migration-search-heading">
        <div class="panel-header"><h2 id="migration-search-heading">Penelusuran karyawan</h2></div>
        <form class="filter-bar" method="get" action="<?=e(url('migrasi'))?>">
            <div class="field wide"><label class="form-label" for="migration-employee">Nama atau NIP</label><input class="form-control" id="migration-employee" data-employee-dropdown list="migration-employees" name="nip" value="<?=e($employeeQuery)?>" maxlength="150" placeholder="Pilih atau cari karyawan" autocomplete="off"><datalist id="migration-employees"><?php foreach($migrationTrace['employees'] as $employee):?><option value="<?=e($employee['nip'])?>" data-department-name="<?=e($employee['nama_bagian'])?>"><?=e($employee['nama'])?></option><?php endforeach;?></datalist></div>
            <div class="migration-search-actions"><?php if($employeeQuery!==''):?><a class="btn btn-light" href="<?=e(url('migrasi'))?>"><?=icon('close')?> Reset</a><?php endif;?><button class="btn btn-primary" type="submit"><?=icon('search')?> Telusuri</button></div>
        </form>
        <?php if($employeeQuery!=='' && !$employeeTrace):?>
            <div class="panel-body migration-search-results">
                <?php if($employeeMatches):?><h3>Hasil pencarian</h3><ul class="migration-match-list"><?php foreach($employeeMatches as $match):?><li><a href="<?=e(url('migrasi',['nip'=>$match['nip']]))?>"><strong><?=e($match['nama'])?></strong><span><?=e($match['nip'])?> · <?=e($match['nama_bagian'])?></span><?=icon('chevron')?></a></li><?php endforeach;?></ul>
                <?php else:?><p class="mb-0">Tidak ada karyawan yang sesuai dengan “<?=e($employeeQuery)?>”. Cari nama atau NIP lain.</p><?php endif;?>
            </div>
        <?php endif;?>
    </section>

    <?php if($employeeTrace):require __DIR__.'/migration-employee.php';endif;?>

    <section class="surface" aria-labelledby="migration-inventory-heading">
        <div class="panel-header"><h2 id="migration-inventory-heading">Kelengkapan tabel sumber</h2><span class="muted-small"><?=e($migrationTrace['totals']['source_rows'])?> baris arsip</span></div>
        <div class="panel-body pt-0 migration-evidence">
            <?php if(!$migrationTrace['reconciled']):?><div class="alert alert-info">Arsip dibandingkan dengan nilai operasional saat ini. Jalankan migrasi lokal terbaru untuk merekam rekonsiliasi dan pelacakan identitas sumber.</div>
            <?php elseif($migrationTrace['complete']):?><p><span class="badge-verified">Pemetaan cocok</span> Seluruh baris operasional sumber cocok dengan tujuan.</p>
            <?php else:?><div class="alert alert-warning">Ada <?=e($migrationTrace['totals']['problems'])?> masalah dan <?=e($migrationTrace['totals']['preserved'])?> perubahan atau penghapusan lokal yang dipertahankan. Periksa rincian tabel dan karyawan; hasil belum dinyatakan lengkap.</div><?php endif;?>
            <?php if($migrationTrace['unrepresented_issues']):?><ul class="migration-issues migration-unrepresented-issues"><?php foreach($migrationTrace['unrepresented_issues'] as $issue):?><li><?php if(!empty($issue['table'])):?><strong class="code-text"><?=e($issue['table'])?></strong>: <?php endif;?><?=e($issue['message'] ?? $issue['code'] ?? 'Masalah pemetaan')?></li><?php endforeach;?></ul><?php endif;?>
            <p class="muted-small mb-0">Master yang kosong pada sumber tetap kosong. Nominal transaksi historis ditelusuri terpisah dan tidak digunakan untuk membuat tarif atau potongan master.</p>
            <?php if($migrationTrace['mapping_version']):?><p class="muted-small mt-2 mb-0">Versi pemetaan: <span class="code-text"><?=e($migrationTrace['mapping_version'])?></span></p><?php endif;?>
        </div>
        <div class="table-responsive"><table class="table app-table migration-inventory-table"><caption class="visually-hidden">Perbandingan arsip sumber dengan tabel operasional; perbedaan lokal dipertahankan.</caption><thead><tr><th>Tabel sumber / tujuan</th><th class="text-end">Kolom</th><th class="text-end">Baris sumber</th><th class="text-end">Cocok</th><th class="text-end">Lokal dipertahankan</th><th class="text-end">Masalah</th><th>Status dan keterangan</th></tr></thead><tbody>
        <?php foreach($tables as $table):?><tr>
            <td><a class="code-text" href="<?=e(url('arsip',['table'=>$table['table_name']]))?>"><?=e($table['table_name'])?></a><?php if($table['target']):?><small class="cell-subtitle">→ <?php if(isset(App\Domain\MasterModules::all()[$table['target']])):?><a href="<?=e(url('master/'.$table['target']))?>"><?=e($table['target'])?></a><?php elseif($table['target']==='penghasilan'):?><a href="<?=e(url('transaksi'))?>">penghasilan</a><?php else:?><?=e($table['target'])?><?php endif;?></small><?php endif;?></td>
            <td class="text-end"><?=e($table['column_count'])?></td><td class="text-end"><?=e($table['row_count'])?></td><td class="text-end"><?=e($table['counts']['mapped'])?></td><td class="text-end"><?=e($table['counts']['preserved'])?></td><td class="text-end"><?=e($table['counts']['problems'])?></td>
<td><span class="<?=$table['status']==='mapped'?'badge-verified':'badge-pending'?>"><?=e(LegacyMigrationTrace::statusLabel($table['status']))?></span><p class="muted-small mt-2 mb-0"><?=e($table['runtime_note'] ?: $table['reason'])?></p><?php if($table['issues']):?><details class="migration-table-issues"><summary><?=count($table['issues'])?> catatan pemetaan</summary><ul class="migration-issues"><?php foreach($table['issues'] as $issue):?><li><?php if(!empty($issue['source_ordinal'])):?><a href="<?=e(url('arsip',['table'=>$table['table_name'],'page'=>(int)ceil($issue['source_ordinal']/25)]))?>">Baris <?=e($issue['source_ordinal'])?></a>: <?php endif;?><?=e($issue['message'] ?? $issue['code'] ?? 'Masalah pemetaan')?></li><?php endforeach;?></ul></details><?php endif;?></td>
        </tr><?php endforeach;?>
        <?php if(!$tables):?><tr><td colspan="7" class="table-empty">Inventaris tersedia setelah backup sumber dipulihkan dan diimpor melalui perintah lokal.</td></tr><?php endif;?></tbody></table></div>
    </section>

    <section class="surface" aria-labelledby="migration-history-heading"><div class="panel-header"><h2 id="migration-history-heading">Riwayat pemulihan &amp; rekonsiliasi</h2></div><div class="panel-body pt-0">
        <?php if(!$runs):?><div class="empty-state compact"><?=icon('database')?><strong>Belum ada impor yang dijalankan</strong><p>Jalankan perintah migrasi lokal untuk memulihkan dan memeriksa backup sumber.</p></div><?php endif;?>
        <?php foreach($runs as $index=>$run):$summary=json_decode($run['summary'],true) ?: [];$exceptions=$summary['exceptions'] ?? $summary['issues'] ?? [];?><details class="migration-run"<?=$index===0?' open':''?>><summary><span><strong><?=e($run['source_database'])?></strong><small><?=e($run['created_at'])?></small></span><span class="<?=$run['status']==='verified'?'badge-verified':'badge-pending'?>"><?=e($run['status']==='verified'?'Terverifikasi':($run['status']==='needs_attention'?'Perlu diperiksa':$run['status']))?></span></summary><div class="migration-run-content"><p class="muted-small"><?=e($summary['tables']??0)?> tabel · <?=e($summary['rows']??0)?> baris arsip · <?=e($summary['operational_rows']??0)?> baris operasional</p><p class="muted-small migration-source-hash">SHA-256: <?=e($run['source_sha256'])?></p><?php if($exceptions):?><ul class="migration-issues"><?php foreach($exceptions as $issue):?><li><?php if(is_array($issue)):?><?php if(!empty($issue['table'])):?><strong><?=e($issue['table'])?></strong><?php endif;?><?php if(!empty($issue['source_ordinal'])):?> · baris <?=e($issue['source_ordinal'])?><?php endif;?>: <?=e($issue['message'] ?? $issue['code'] ?? 'Masalah pemetaan')?><?php else:?><?=e($issue)?><?php endif;?></li><?php endforeach;?></ul><?php endif;?></div></details><?php endforeach;?>
        <div class="form-note mt-3"><?=icon('lock')?><span>Migrasi dijalankan melalui perintah lokal. Halaman ini hanya membaca arsip, hasil rekonsiliasi, dan data operasional.</span></div>
    </div></section>
</div>
