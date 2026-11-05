<?php
$incomeFields=array_keys(App\Domain\PrintLayout::INCOME);
$deductionFields=array_keys(App\Domain\PrintLayout::DEDUCTIONS);
$amountClass=static function(mixed $value): string {$length=strlen(App\Domain\PrintLayout::amount($value));return $length>20?'slip-amount-extra-long':($length>16?'slip-amount-long':'');};
$quantityFields=['ulembur'=>'lembur','uinfal'=>'infal','uovertime'=>'overtime'];
?>
<article class="slip legacy-slip" aria-label="Slip gaji <?=e($row['nama'])?>" data-slip-nip="<?=e($row['nip'])?>">
 <div class="legacy-slip-body">
 <h2 class="legacy-slip-title">SLIP GAJI KARYAWAN YAYASAN MUTIARA HIKMAH</h2>
 <div class="slip-identity legacy-slip-identity">
  <div class="legacy-identity-row"><span>NIP</span><span>:</span><span><?=e($row['nip'])?></span></div>
  <div class="legacy-identity-row"><span>Nama</span><span>:</span><span><?=e($row['nama'])?></span></div>
 </div>
 <div class="legacy-slip-attendance">
  <div class="legacy-attendance-pair"><span class="legacy-attendance-metric"><span>Jumlah Hari Efektif</span><span>:</span><span data-slip-quantity="hefektif"><?=e($row['hefektif'])?></span></span><span class="legacy-attendance-metric"><span>Masuk Kerja</span><span>:</span><span data-slip-quantity="hmasuk"><?=e($row['hmasuk'])?></span></span></div>
  <div class="legacy-slip-period"><span>Bulan</span><span>:</span><span><?=e(month_name((int)$row['bulan']))?></span><span><?=e($row['tahun'])?></span></div>
 </div>
 <table class="legacy-slip-components">
  <colgroup><col class="slip-income-label-col"><col class="slip-colon-col"><col class="slip-currency-col"><col class="slip-income-amount-col"><col class="slip-gutter-col"><col class="slip-deduction-label-col"><col class="slip-colon-col"><col class="slip-currency-col"><col class="slip-deduction-amount-col"></colgroup>
  <thead><tr><th colspan="4" scope="colgroup">Penghasilan</th><th class="slip-gutter"></th><th colspan="4" scope="colgroup">Potongan</th></tr></thead>
  <tbody>
  <?php foreach($incomeFields as $index=>$incomeField):$deductionField=$deductionFields[$index]??null;?>
   <tr>
    <td><span class="legacy-slip-component-label"><span><?=e(App\Domain\PrintLayout::INCOME[$incomeField])?></span><?php if(isset($quantityFields[$incomeField])):$quantity=$quantityFields[$incomeField];?><span data-slip-quantity="<?=e($quantity)?>"><?=e($row[$quantity]??'')?></span><?php endif;?></span></td><td>:</td><td>Rp.</td><td class="legacy-slip-amount <?=$amountClass($row[$incomeField])?>" data-slip-field="<?=e($incomeField)?>"><?=e(App\Domain\PrintLayout::amount($row[$incomeField]))?></td>
    <td class="slip-gutter"></td>
    <?php if($deductionField!==null):?><td><?=e(App\Domain\PrintLayout::DEDUCTIONS[$deductionField])?></td><td>:</td><td>Rp.</td><td class="legacy-slip-amount <?=$amountClass($row[$deductionField])?>" data-slip-field="<?=e($deductionField)?>"><?=e(App\Domain\PrintLayout::amount($row[$deductionField]))?></td><?php else:?><td colspan="4"></td><?php endif;?>
   </tr>
  <?php endforeach;?>
  </tbody>
  <tfoot>
   <tr><td>Jumlah Kotor</td><td>:</td><td>Rp.</td><td class="legacy-slip-amount <?=$amountClass($row['tpenghasilan'])?>" data-slip-field="tpenghasilan"><?=e(App\Domain\PrintLayout::amount($row['tpenghasilan']))?></td><td class="slip-gutter"></td><td>Total Potongan</td><td>:</td><td>Rp.</td><td class="legacy-slip-amount <?=$amountClass($row['tpotongan'])?>" data-slip-field="tpotongan"><?=e(App\Domain\PrintLayout::amount($row['tpotongan']))?></td></tr>
   <tr class="legacy-slip-net"><td colspan="4"></td><td class="slip-gutter"></td><td>Gaji Bersih</td><td>:</td><td>Rp.</td><td class="legacy-slip-amount <?=$amountClass($row['tbersih'])?>" data-slip-field="tbersih"><?=e(App\Domain\PrintLayout::amount($row['tbersih']))?></td></tr>
  </tfoot>
 </table>
 <div class="legacy-slip-signatures">
  <div class="legacy-slip-issued"><?=e($print['location'])?>, <time data-print-timestamp><?=e($print['timestamp'])?></time></div>
  <div class="legacy-slip-accounting"><span>Accounting</span><div class="legacy-signature-space"></div><span class="<?=mb_strlen($print['officer'])>60?'signature-name-long':''?>"><?=e($print['officer'])?></span></div>
  <div class="legacy-slip-receiver"><span>Penerima</span><div class="legacy-signature-space"></div><span class="<?=mb_strlen($row['nama'])>60?'signature-name-long':''?>"><?=e($row['nama'])?></span></div>
 </div>
 </div>
</article>
