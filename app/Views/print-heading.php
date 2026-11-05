<header class="print-document-heading">
 <h2><?=e(mb_strtoupper($printHeadingTitle))?></h2>
 <p>YAYASAN MUTIARA HIKMAH</p>
 <p><?=e($type==='gapok'?'Struktur gaji master':$rangeLabel)?> · <?=$scope==='semua'?'Semua '.($employeeScope?'karyawan':'bagian'):($employeeScope?'NIP '.e($nip):'Bagian '.e($period['bagian']))?></p>
 <p class="print-document-meta"><?=e($print['location'])?>, <time data-print-timestamp><?=e($print['timestamp'])?></time> · Dicetak oleh <?=e($print['officer'])?></p>
</header>
