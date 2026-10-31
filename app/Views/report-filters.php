<?php $filterLayout=$isRange?'range':($type==='gapok'?'master':'monthly');$hasDetail=!$isSlip&&!in_array($type,['gapok','absensi'],true);$yearReadonly=false;$yearNumber=true; ?>
<form method="get" class="filter-bar report-filters report-filters-<?=e($filterLayout)?> <?=$hasDetail?'has-detail':''?>" data-report-type="<?=e($type)?>">
 <input type="hidden" name="type" value="<?=e($type)?>">
 <?php if($isRange):?>
 <div class="report-periods">
  <?php foreach([['dari','Dari','Periode Awal',$startMonth,$startYear],['sampai','Sampai','Periode Akhir',$endMonth,$endYear]] as [$prefix,$label,$legend,$month,$year]):?>
  <fieldset class="report-period-group"><legend><?=e($legend)?></legend><div class="report-period-fields">
   <div class="field"><label class="form-label" for="<?=e($prefix)?>-month"><?=e($label)?> bulan</label><select class="form-select" id="<?=e($prefix)?>-month" name="<?=e($prefix)?>_bulan"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>"<?=selected($month,$m)?>><?=month_name($m)?></option><?php endfor;?></select></div>
   <div class="field"><label class="form-label" for="<?=e($prefix)?>-year"><?=e($label)?> tahun</label><?php $yearId=$prefix.'-year';$yearName=$prefix.'_tahun';$yearValue=$year;require __DIR__.'/year-control.php'; ?></div>
  </div></fieldset>
  <?php endforeach;?>
 </div>
 <?php endif;?>
 <div class="report-filter-fields">
  <?php if(!$isRange&&$type!=='gapok'):?>
  <div class="field report-month"><label class="form-label" for="report-month">Bulan</label><select class="form-select" id="report-month" name="bulan"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>"<?=selected($period['bulan'],$m)?>><?=month_name($m)?></option><?php endfor;?></select></div>
  <div class="field report-year"><label class="form-label" for="report-year">Tahun</label><?php $yearId='report-year';$yearName='tahun';$yearValue=$period['tahun'];require __DIR__.'/year-control.php'; ?></div>
  <?php endif;?>
  <div class="field report-scope"><label class="form-label" for="report-scope">Cakupan</label><select class="form-select" id="report-scope" name="<?=$employeeScope?'cakupan_karyawan':'cakupan_bagian'?>"><option value="semua"<?=selected($scope,'semua')?>>Semua <?=$employeeScope?'karyawan':'bagian'?></option><option value="tertentu"<?=selected($scope,'tertentu')?>><?=$employeeScope?'Karyawan':'Bagian'?> tertentu</option></select></div>
  <div class="field report-reference">
   <?php if($employeeScope):?>
   <label class="form-label" for="report-employee">Karyawan</label><input class="form-control" id="report-employee" name="nip" list="report-employees" maxlength="15" value="<?=e($nip)?>" placeholder="Cari nama atau NIP"><datalist id="report-employees"><?php foreach($employees as $employee):?><option value="<?=e($employee['nip'])?>"><?=e($employee['nama'])?></option><?php endforeach;?></datalist>
   <?php else:?>
   <label class="form-label" for="report-department">Bagian</label><select class="form-select" id="report-department" name="bagian"><option value="">Pilih bagian</option><?php foreach($departments as $d):?><option value="<?=e($d['kd_bagian'])?>"<?=selected($period['bagian'],$d['kd_bagian'])?>><?=e($d['nama_bagian'])?></option><?php endforeach;?></select>
   <?php endif;?>
  </div>
  <?php if($hasDetail):?><div class="field report-detail"><label class="form-label" for="report-detail">Kolom</label><select class="form-select" id="report-detail" name="rincian"><option value="0"<?=selected($detailed?'1':'0','0')?>>Ringkasan</option><option value="1"<?=selected($detailed?'1':'0','1')?>>Seluruh komponen</option></select></div><?php endif;?>
  <div class="report-actions"><a class="btn btn-light" href="<?=e(url('laporan',['type'=>$type]))?>" title="Kembalikan filter ke awal dan tutup pratinjau"><?=icon('close')?> Reset</a><button class="btn btn-primary" type="submit" name="view" value="1"><?=icon('search')?> Tampilkan</button></div>
 </div>
</form>
