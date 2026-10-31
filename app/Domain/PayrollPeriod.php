<?php
declare(strict_types=1);
namespace App\Domain;
final class PayrollPeriod {
    public static function value(mixed $raw,string $label,int $min,int $max): int {
        $text=is_string($raw)||is_int($raw)?(string)$raw:'';$normal=ltrim($text,'0')?:'0';
        if($text===''||!ctype_digit($text)||strlen($normal)>strlen((string)$max)||(int)$normal<$min||(int)$normal>$max){
            $field=match($label){'Bulan'=>'bulan','Tahun'=>'tahun',default=>str_replace(' ','_',strtolower($label))};
            throw new PayrollValidationException("$label harus berupa bilangan bulat antara $min dan $max.",$field,'invalid_period');
        }
        return (int)$normal;
    }
}
