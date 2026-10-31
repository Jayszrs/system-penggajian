<?php
$incomeFields=array_keys(App\Domain\PrintLayout::INCOME);
$deductionFields=array_keys(App\Domain\PrintLayout::DEDUCTIONS);
?>
<article class="slip legacy-slip" aria-label="Slip gaji <?=e($row['nama'])?>" data-slip-nip="<?=e($row['nip'])?>">
 <h2 class="legacy-slip-title">SLIP GAJI KARYAWAN YAYASAN MUTIARA HIKMAH</h2>
 <div class="slip-identity legacy-slip-identity">
  <div class="legacy-identity-row"><span>NIP</span><span>:</span><span><?=e($row['nip'])?></span></div>
  <div class="legacy-identity-row"><span>Nama</span><span>:</span><span><?=e($row['nama'])?></span></div>
 </div>
 <div class="legacy-slip-attendance">
  <span>Jumlah Hari Efektif : <?=e($row['hefektif'])?></span>
  <span>Masuk Kerja : <?=e($row['hmasuk'])?></span>
  <span>Bulan : <?=e(month_name((int)$row['bulan']).' '.$row['tahun'])?></span>
 </div>
 <table class="legacy-slip-components">
  <colgroup><?php for($side=0;$side<2;$side++):?><col class="slip-label-col"><col class="slip-colon-col"><col class="slip-currency-col"><col class="slip-amount-col"><?php endfor;?></colgroup>
  <thead><tr><th colspan="4" scope="colgroup">Penghasilan</th><th colspan="4" scope="colgroup">Potongan</th></tr></thead>
  <tbody>
  <?php foreach($incomeFields as $index=>$incomeField):$deductionField=$deductionFields[$index]??null;?>
   <tr>
    <td><?=e(App\Domain\PrintLayout::INCOME[$incomeField])?></td><td>:</td><td>Rp</td><td class="legacy-slip-amount" data-slip-field="<?=e($incomeField)?>"><?=e(App\Domain\PrintLayout::amount($row[$incomeField]))?></td>
    <?php if($deductionField!==null):?><td><?=e(App\Domain\PrintLayout::DEDUCTIONS[$deductionField])?></td><td>:</td><td>Rp</td><td class="legacy-slip-amount" data-slip-field="<?=e($deductionField)?>"><?=e(App\Domain\PrintLayout::amount($row[$deductionField]))?></td><?php else:?><td colspan="4"></td><?php endif;?>
   </tr>
  <?php endforeach;?>
  </tbody>
  <tfoot>
   <tr><td>Jumlah Kotor</td><td>:</td><td>Rp</td><td class="legacy-slip-amount" data-slip-field="tpenghasilan"><?=e(App\Domain\PrintLayout::amount($row['tpenghasilan']))?></td><td>Total Potongan</td><td>:</td><td>Rp</td><td class="legacy-slip-amount" data-slip-field="tpotongan"><?=e(App\Domain\PrintLayout::amount($row['tpotongan']))?></td></tr>
   <tr class="legacy-slip-net"><td colspan="4"></td><td><em>Gaji Bersih</em></td><td>:</td><td>Rp</td><td class="legacy-slip-amount" data-slip-field="tbersih"><?=e(App\Domain\PrintLayout::amount($row['tbersih']))?></td></tr>
  </tfoot>
 </table>
 <div class="legacy-slip-signatures">
  <div class="legacy-slip-accounting"><span>Accounting</span><div class="legacy-signature-space"></div><span><?=e($print['officer'])?></span></div>
  <div><div class="legacy-slip-issued"><?=e($print['location'])?>, <time data-print-timestamp><?=e($print['timestamp'])?></time></div><span>Penerima</span><div class="legacy-signature-space"></div><span><?=e($row['nama'])?></span></div>
 </div>
</article>
