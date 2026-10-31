<?php
$feedbackEvents=[];
foreach(($flashMessages??[]) as $entry)$feedbackEvents[]=['severity'=>match($entry['type']){'success'=>'success','warning'=>'warning',default=>'error'},'title'=>$entry['type']==='success'?'Berhasil disimpan':'Periksa kembali','message'=>$entry['message']];
if(!empty($feedback))$feedbackEvents[]=$feedback;
elseif(!empty($error))$feedbackEvents[]=['severity'=>'error','title'=>'Data belum dapat diproses','message'=>$error];
elseif(!empty($errorMessage))$feedbackEvents[]=['severity'=>'error','title'=>'Permintaan belum berhasil','message'=>$errorMessage];
?>
<div class="modal feedback-modal" id="feedback-modal" tabindex="-1" aria-labelledby="feedback-title" aria-describedby="feedback-message" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header border-0 pb-0"><span class="feedback-symbol" data-feedback-symbol><?=icon('info')?></span><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup pemberitahuan"></button></div>
  <div class="modal-body"><h2 class="modal-title mb-3" id="feedback-title">Pemberitahuan</h2><p class="feedback-message mb-0" id="feedback-message"></p><ul class="feedback-issues mt-3 mb-0" data-feedback-issues hidden></ul></div>
  <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light" id="feedback-cancel" data-bs-dismiss="modal" hidden>Kembali Periksa</button><button type="button" class="btn btn-primary" id="feedback-accept">Mengerti</button></div>
 </div></div>
</div>
<script type="application/json" id="feedback-events"><?=json_encode($feedbackEvents,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
<?php if(!empty($feedback['confirmation'])):?><noscript><div class="alert alert-warning"><p><?=e($feedback['message'])?></p><ul><?php foreach(($feedback['issues']??[]) as $issue):?><li><?=e($issue['message'])?></li><?php endforeach;?></ul><button type="submit" class="btn btn-primary" form="payroll-form" name="warnings_accepted" value="1">Lanjutkan Simpan</button></div></noscript><?php endif;?>
