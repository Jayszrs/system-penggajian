<?php
declare(strict_types=1);
namespace App\Domain;

use InvalidArgumentException;

final class PayrollValidationException extends InvalidArgumentException
{
    /** @var list<array{code:string,severity:string,field:string,message:string}> */
    public array $issues;

    public function __construct(string $message, string $field = '', string $issueCode = 'invalid_input', array $issues = [])
    {
        parent::__construct($message);
        $this->issues = $issues ?: [['code' => $issueCode, 'severity' => 'error', 'field' => $field, 'message' => $message]];
    }

    public static function fromIssues(array $issues): self
    {
        return new self((string)($issues[0]['message'] ?? 'Periksa kembali komponen penghasilan.'), '', 'invalid_input', $issues);
    }
}
