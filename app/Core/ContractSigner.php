<?php
declare(strict_types=1);

namespace App\Core;

final class ContractSigner
{
    public static function loanHash(array $loan): string
    {
        $key = (string) App::config('security.app_key', 'credimax');
        return hash_hmac(
            'sha256',
            (string) ($loan['id'] ?? '') . '|' . (string) ($loan['loan_code'] ?? ''),
            $key
        );
    }

    /** @return array<string,mixed>|null */
    public static function verifyHash(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }
        $rows = App::db()->fetchAll('SELECT * FROM loans ORDER BY id DESC LIMIT 500');
        foreach ($rows as $loan) {
            if (hash_equals(self::loanHash($loan), $hash)) {
                return $loan;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $loan @param array<string,mixed> $borrower @param list<array<string,mixed>> $lenders @param array<string,mixed> $product */
    public static function renderLoanContract(array $loan, array $borrower, array $lenders, array $product): string
    {
        $borrowerName = e(trim(($borrower['first_name'] ?? '') . ' ' . ($borrower['last_name'] ?? '')));
        $productName = e((string) ($product['name'] ?? 'Crédito P2P'));
        $code = e((string) ($loan['loan_code'] ?? ''));
        $principal = money((float) ($loan['principal'] ?? 0));
        $installment = money((float) ($loan['installment_amount'] ?? 0));
        $total = money((float) ($loan['total_payable'] ?? 0));
        $rate = number_format((float) ($loan['annual_rate'] ?? 0), 2, ',', '.');
        $term = (int) ($loan['term_months'] ?? 0);
        $hash = self::loanHash($loan);
        $lenderRows = '';
        foreach ($lenders as $l) {
            $lenderRows .= '<tr><td>' . e((string) ($l['credimax_id'] ?? '')) . '</td><td>'
                . e(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''))) . '</td><td>'
                . money((float) ($l['amount'] ?? 0)) . '</td></tr>';
        }
        if ($lenderRows === '') {
            $lenderRows = '<tr><td colspan="3">Sin fondeo registrado</td></tr>';
        }
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Contrato ' . $code . '</title>'
            . '<style>body{font-family:Georgia,serif;max-width:820px;margin:2rem auto;line-height:1.5;color:#111}'
            . 'table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:.5rem}</style></head><body>'
            . '<h1>Contrato de crédito Credimax</h1>'
            . '<p><strong>Código:</strong> ' . $code . '<br><strong>Producto:</strong> ' . $productName . '</p>'
            . '<h2>Deudor</h2><p>' . $borrowerName . ' — ' . e((string) ($borrower['email'] ?? '')) . '</p>'
            . '<h2>Condiciones</h2><ul>'
            . '<li>Capital: ' . $principal . '</li>'
            . '<li>TNA: ' . $rate . '%</li>'
            . '<li>Plazo: ' . $term . ' meses</li>'
            . '<li>Cuota: ' . $installment . '</li>'
            . '<li>Total: ' . $total . '</li></ul>'
            . '<h2>Inversores</h2><table><tr><th>ID</th><th>Nombre</th><th>Monto</th></tr>' . $lenderRows . '</table>'
            . '<p class="muted">Huella de verificación: <code>' . e($hash) . '</code></p>'
            . '<p>Documento generado ' . e(date('Y-m-d H:i:s')) . '.</p></body></html>';
    }
}
