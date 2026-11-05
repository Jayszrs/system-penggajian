<?php if(!empty($yearNumber)):?>
<input class="form-control report-year-number" type="number" inputmode="numeric" data-year-control id="<?=e($yearId)?>" name="<?=e($yearName)?>" min="1900" max="9999" step="1" value="<?=e($yearValue)?>" required <?=!empty($yearReadonly)?'readonly':''?>>
<?php else:?>
<input class="form-control" type="text" inputmode="numeric" data-year-control id="<?=e($yearId)?>" name="<?=e($yearName)?>" list="<?=e($yearId)?>-years" value="<?=e($yearValue)?>" pattern="[0-9]{4}" maxlength="4" required <?=!empty($yearReadonly)?'readonly':''?>>
<datalist id="<?=e($yearId)?>-years"><?php foreach($yearOptions as $suggestedYear):?><option value="<?=$suggestedYear?>"></option><?php endforeach;?></datalist>
<?php endif;?>
