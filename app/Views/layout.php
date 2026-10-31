<?php
$currentPath=app_request_path();
$masterLinks=[
 'bagian'=>'Data Bagian',
 'tunj_harian'=>'Data Harian',
 'tunj_konsumsi'=>'Data Konsumsi',
 'tunj_infal'=>'Data Infal',
 'tunj_overtime'=>'Data Overtime',
 'tunj_jam'=>'Data Kelebihan Mengajar',
 'p_anak'=>'Potongan Anak',
 'p_bjbs'=>'Potongan BPJS',
 'p_lunak'=>'Potongan Pinjaman Lunak',
 'p_bank'=>'Potongan Pinjaman Bank',
 'p_lain'=>'Potongan Lain-lain',
];
$reportLinks=[
 'slip_karyawan'=>'Slip Gaji',
 'slip_bagian'=>'Slip Gaji per Bagian',
 'rekap_bagian'=>'Rekap Gaji per Bagian',
 'rekap_periode'=>'Rekap Gaji per Periode',
 'rekap_periode_bagian'=>'Rekap Gaji per Periode per Bagian',
 'absensi'=>'Rekap Absensi',
 'gapok'=>'Rekap Gapok',
];
$activeNavGroup=match(true){
 $currentPath==='/master/karyawan'||str_starts_with($currentPath,'/transaksi')=>'transaksi',
 $currentPath==='/laporan'=>'laporan',
 str_starts_with($currentPath,'/master/')=>'master',
 default=>null,
};
$navigationSection=$currentPath==='/'?'Dashboard':($activeNavGroup!==null?ucfirst($activeNavGroup):($section??'Penggajian'));
$selectedReport=(string)($_GET['type']??'slip_karyawan');
if(!isset($reportLinks[$selectedReport]))$selectedReport='slip_karyawan';
$navGroups=[
 'master'=>['Master','building',array_map(static fn($key,$label)=>['label'=>$label,'path'=>'master/'.$key,'query'=>[],'active'=>$currentPath==='/master/'.$key],array_keys($masterLinks),array_values($masterLinks))],
 'transaksi'=>['Transaksi','receipt',[
  ['label'=>'Karyawan','path'=>'master/karyawan','query'=>[],'active'=>$currentPath==='/master/karyawan'],
  ['label'=>'Penggajian','path'=>'transaksi','query'=>[],'active'=>str_starts_with($currentPath,'/transaksi')],
 ]],
 'laporan'=>['Laporan','chart',array_map(static fn($key,$label)=>['label'=>$label,'path'=>'laporan','query'=>['type'=>$key],'active'=>$currentPath==='/laporan'&&$selectedReport===$key],array_keys($reportLinks),array_values($reportLinks))],
];
$flashMessages=$_SESSION['flash']??[];unset($_SESSION['flash']);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="app-base-path" content="<?=e(app_base_path())?>"><meta name="csrf-token" content="<?=e(csrf_token())?>"><meta name="theme-color" content="#0B6B43"><title><?=e($title??'Penggajian')?> · Mutiara Hikmah</title><link rel="icon" type="image/svg+xml" href="<?=e(url('public/assets/brand.svg'))?>"><link rel="stylesheet" href="<?=e(url('public/assets/vendor/bootstrap.min.css'))?>"><link rel="stylesheet" href="<?=e(url('public/assets/app.css',['v'=>(string)filemtime(BASE_PATH.'/public/assets/app.css')]))?>"></head><body>
<a class="skip-link" href="#main-content">Lewati navigasi</a>
<button class="sidebar-overlay" aria-label="Tutup navigasi" data-sidebar-close></button>
<aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
 <a href="<?=e(url(''))?>" class="brand"><span class="brand-mark"><?=icon('leaf')?></span><span>MUTIARA<span class="brand-sub">SISTEM PENGGAJIAN</span></span></a>
 <div class="nav-caption">RUANG KERJA</div>
 <nav class="main-nav" aria-label="Ruang kerja"><a class="nav-item-link <?=$currentPath==='/'?'active':''?>" href="<?=e(url(''))?>" <?=$currentPath==='/'?'aria-current="page"':''?>><span class="nav-icon"><?=icon('grid')?></span><span>Dashboard</span><?php if($currentPath==='/'):?><span class="active-dot"></span><?php endif;?></a>
 <?php foreach($navGroups as $group=>[$label,$glyph,$links]):$activeGroup=$activeNavGroup===$group;?>
 <details class="nav-group" data-nav-group="<?=e($group)?>" <?=$activeGroup?'open':''?>><summary class="nav-item-link <?=$activeGroup?'group-active':''?>"><span class="nav-icon"><?=icon($glyph)?></span><span><?=e($label)?></span><?=icon('chevron-down','nav-chevron')?></summary><div class="nav-submenu">
 <?php foreach($links as $link):?><a href="<?=e(url($link['path'],$link['query']))?>" class="<?=$link['active']?'active':''?>" <?=$link['active']?'aria-current="page"':''?>><?=e($link['label'])?></a><?php endforeach;?>
 </div></details><?php endforeach;?>
 </nav>
 <div class="nav-caption nav-caption-secondary">PENGATURAN</div>
 <nav class="main-nav" aria-label="Pengaturan">
 <a class="nav-item-link <?=$currentPath==='/migrasi'||$currentPath==='/arsip'?'active':''?>" href="<?=e(url('migrasi'))?>"><span class="nav-icon"><?=icon('database')?></span><span>Data & Migrasi</span></a>
 <a class="nav-item-link <?=$currentPath==='/profil'?'active':''?>" href="<?=e(url('profil'))?>"><span class="nav-icon"><?=icon('user')?></span><span>Profil Saya</span></a></nav>
 <div class="sidebar-bottom"><div class="sidebar-note"><span class="note-icon"><?=icon('shield')?></span><strong>Ruang kerja Anda,<br>lebih tertata.</strong><p>Kelola penghasilan dengan<br>mudah dan transparan.</p><a href="<?=e(url('transaksi/form'))?>">Mulai transaksi <?=icon('arrow-right')?></a></div><form action="<?=e(url('logout'))?>" method="post"><?=csrf_field()?><button class="logout-button" type="submit"><?=icon('logout')?> Keluar akun</button></form><div class="sidebar-version">Mutiara Hikmah <span>v1.0</span></div></div>
</aside>
<div class="app-main"><header class="topbar"><div class="breadcrumb-area"><button class="mobile-menu icon-button" data-sidebar-toggle aria-label="Buka navigasi" aria-controls="sidebar" aria-expanded="false"><?=icon('menu')?></button><span>Ruang kerja</span><?=icon('chevron')?><strong><?=e($navigationSection)?></strong></div><div class="topbar-right"><span class="local-badge"><span></span> Aplikasi lokal</span><span class="topbar-divider"></span><div class="user-chip"><span class="avatar"><?=e(mb_strtoupper(mb_substr($_SESSION['user']['name']??'P',0,1)))?></span><span><strong><?=e($_SESSION['user']['name']??'Petugas')?></strong><small>Petugas penggajian</small></span></div></div></header>
<main id="main-content" class="main-content">
<?php foreach($flashMessages as $message):?><div class="alert alert-<?=e($message['type'])?> alert-dismissible fade show" role="status"><?=e($message['message'])?><button class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div><?php endforeach;?>
<?=$content?>
<footer class="page-footer"><span>© <?=date('Y')?> Yayasan Mutiara Hikmah</span><span>Dikelola dengan cermat, untuk setiap penghasilan.</span></footer>
</main></div>
<script src="<?=e(url('public/assets/vendor/bootstrap.bundle.min.js'))?>" defer></script><script src="<?=e(url('public/assets/vendor/chart.umd.min.js'))?>" defer></script><script src="<?=e(url('public/assets/app.js',['v'=>(string)filemtime(BASE_PATH.'/public/assets/app.js')]))?>" defer></script>
</body></html>
