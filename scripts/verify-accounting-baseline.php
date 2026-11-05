<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$pointer=BASE_PATH.'/storage/audit/current-accounting-audit.txt';
if(!is_file($pointer))throw new RuntimeException('Baseline lokal belum tersedia.');
$directory=trim(file_get_contents($pointer));
$baseline=json_decode(file_get_contents($directory.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
$db=App\Core\Database::connection();
if($db->query('SELECT DATABASE()')->fetchColumn()!==$baseline['database'])throw new RuntimeException('Database tidak sesuai baseline.');
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();$results=[];$valid=true;
foreach($baseline['tables'] as $table=>$source){
    $columns=implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$source['columns']));
    $rows=$db->query("SELECT $columns FROM `$table` ORDER BY `{$source['order']}`")->fetchAll();
    $hash=hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $match=count($rows)===$source['rows']&&hash_equals($source['sha256'],$hash);
    $results[$table]=['rows'=>count($rows),'unchanged'=>$match];$valid=$valid&&$match;
}
$db->commit();file_put_contents($directory.'/after.json',json_encode(['verified_at'=>date(DATE_ATOM),'verified'=>$valid,'tables'=>$results],JSON_PRETTY_PRINT));
echo json_encode(['verified'=>$valid,'tables'=>$results],JSON_PRETTY_PRINT),PHP_EOL;
exit($valid?0:1);
