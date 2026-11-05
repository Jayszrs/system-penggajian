<?php
$groupTotals=[];$lastGroup=null;
$totalCell=static function(string $field,array $values) use($isSummable,$isNumber,$isMoney): string {
    if($isSummable($field))return $isMoney($field)?money($values[$field]??'0'):e($values[$field]??'0');
    return $isNumber($field)?'—':'';
};
?>
<div class="table-responsive"><table class="report-table">
<thead><tr class="report-print-heading"><th colspan="<?=count($columns)+1?>"><?php require __DIR__.'/print-heading.php'; ?></th></tr><tr><th>No.</th><?php foreach($columns as $field=>$label):?>
    <th data-field="<?=e($field)?>" class="<?=$isNumber($field)?'text-end':''?>"><?=e($label)?></th>
<?php endforeach;?></tr></thead>
<tbody>
<?php foreach($rows as $index=>$row):$group=(string)($row['kd_bagian']??'');
    if($lastGroup!==null && $group!==$lastGroup):?>
    <tr class="report-subtotal"><td></td><?php foreach($columns as $field=>$label):?>
        <td data-field="<?=e($field)?>" class="<?=$isNumber($field)?'text-end amount':''?>"><?=$field==='nama'?'Subtotal bagian':$totalCell($field,$groupTotals)?></td>
    <?php endforeach;?></tr>
    <?php $groupTotals=[];endif;
    $lastGroup=$group;
    foreach($totals as $field=>$total)$groupTotals[$field]=bcadd($groupTotals[$field]??'0',(string)($row[$field]??0),0);
    ?>
    <tr><td><?=$index+1?></td><?php foreach($columns as $field=>$label):?>
        <td data-field="<?=e($field)?>" class="<?=$isNumber($field)?'text-end amount':''?>"><?=$isMoney($field)?money($row[$field]??null):e($row[$field]??'—')?></td>
    <?php endforeach;?></tr>
<?php endforeach;?>
<?php if($rows):?>
    <tr class="report-subtotal"><td></td><?php foreach($columns as $field=>$label):?>
        <td data-field="<?=e($field)?>" class="<?=$isNumber($field)?'text-end amount':''?>"><?=$field==='nama'?'Subtotal bagian':$totalCell($field,$groupTotals)?></td>
    <?php endforeach;?></tr>
<?php endif;?>
</tbody>
<tfoot><tr><td></td><?php foreach($columns as $field=>$label):?>
    <td data-field="<?=e($field)?>" class="<?=$isNumber($field)?'text-end amount':''?>"><?=$field==='nama'?'JUMLAH':$totalCell($field,$totals)?></td>
<?php endforeach;?></tr></tfoot>
</table></div>
