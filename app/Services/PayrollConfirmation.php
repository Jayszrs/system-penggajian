<?php
declare(strict_types=1);
namespace App\Services;

final class PayrollConfirmation {
    private static function canonical(mixed $value): mixed {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as &$item)$item=self::canonical($item);unset($item);
        return $value;
    }
    public static function fingerprint(array $result,array $identity): string {
        // This warning describes a previous preview, while the bound values/rates
        // already identify the new calculation that must be acknowledged.
        $result['issues']=array_values(array_filter($result['issues'],static fn($issue)=>$issue['code']!=='calculation_changed'));
        return hash('sha256',json_encode(self::canonical(['identity'=>$identity,'version'=>$result['calculation_version'],'values'=>$result['values'],'snapshot'=>$result['snapshot'],'issues'=>$result['issues']]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function issue(array $result,array $identity): string {
        $_SESSION['payroll_confirmations']??=[];
        foreach($_SESSION['payroll_confirmations'] as $key=>$record)if($record['issued_at']<time()-900)unset($_SESSION['payroll_confirmations'][$key]);
        while(count($_SESSION['payroll_confirmations'])>=24)array_shift($_SESSION['payroll_confirmations']);
        $token=bin2hex(random_bytes(24));
        $_SESSION['payroll_confirmations'][$token]=['issued_at'=>time(),'fingerprint'=>self::fingerprint($result,$identity),'requires_ack'=>count($result['issues'])>0];
        return $token;
    }
    public static function valid(string $token,array $result,array $identity): bool {
        $record=$_SESSION['payroll_confirmations'][$token]??null;
        return $record!==null&&$record['issued_at']>=time()-900&&hash_equals($record['fingerprint'],self::fingerprint($result,$identity));
    }
    public static function consume(string $token): void { unset($_SESSION['payroll_confirmations'][$token]); }
    public static function requiresAcknowledgement(string $token): bool { return !empty($_SESSION['payroll_confirmations'][$token]['requires_ack']); }
}
