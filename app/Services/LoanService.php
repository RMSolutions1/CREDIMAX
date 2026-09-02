<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

final class LoanService
{
    private WalletService $wallet;

    public function __construct(?WalletService $wallet = null)
    {
        $this->wallet = $wallet ?? new WalletService();
    }

    public function createRequest(int $borrowerId, int $productId, float $amount, int $termMonths, string $purpose = ''): int
    {
        $db = App::db();
        $user = $db->fetch('SELECT * FROM users WHERE id = ?', [$borrowerId]);
        if (!$user || !(int) $user['can_borrow']) {
            throw new RuntimeException('No tenés permiso para solicitar créditos.');
        }
        if (($user['kyc_status'] ?? '') !== 'approved' && ($user['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Debés completar y aprobar tu verificación de identidad (KYC) para solicitar créditos.');
        }

        $product = $db->fetch('SELECT * FROM loan_products WHERE id = ? AND is_active = 1', [$productId]);
        if (!$product) {
            throw new RuntimeException('Producto de crédito no disponible.');
        }
        if ($amount < (float) $product['min_amount'] || $amount > (float) $product['max_amount']) {
            throw new RuntimeException('Monto fuera del rango del producto.');
        }
        if ($termMonths < (int) $product['min_term_months'] || $termMonths > (int) $product['max_term_months']) {
            throw new RuntimeException('Plazo fuera del rango permitido.');
        }

        $quote = loan_quote($amount, (float) $product['annual_rate'], $termMonths, (float) $product['origination_fee_pct']);
        $code = generate_loan_code();

        $db->beginTransaction();
        try {
            $loanId = $db->insert('loans', [
                'loan_code' => $code,
                'borrower_id' => $borrowerId,
                'product_id' => $productId,
                'principal' => $amount,
                'funded_amount' => 0,
                'annual_rate' => $product['annual_rate'],
                'tea' => $quote['tea'],
                'cft_tna' => $quote['cft_tna'],
                'cft_tea' => $quote['cft_tea'],
                'origination_fee_amount' => $quote['origination_fee'],
                'term_months' => $termMonths,
                'installment_amount' => $quote['installment'],
                'total_payable' => $quote['total_payable'],
                'purpose' => $purpose,
                'status' => 'open',
            ]);

            foreach ($quote['rows'] as $row) {
                $db->insert('loan_installments', [
                    'loan_id' => $loanId,
                    'installment_number' => $row['number'],
                    'due_date' => $row['due_date'],
                    'principal_portion' => $row['principal'],
                    'interest_portion' => $row['interest'],
                    'fee_portion' => $row['fee'],
                    'total_amount' => $row['total'],
                    'status' => 'pending',
                ]);
            }

            $firstDue = $quote['rows'][0]['due_date'] ?? null;
            if ($firstDue) {
                $db->update('loans', ['next_due_date' => $firstDue], 'id = ?', [$loanId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        notify($borrowerId, 'Solicitud publicada', "Tu crédito {$code} está abierto para fondeo P2P.", url('/loans/' . $loanId));
        audit_log('loan.create', 'loan', (string) $loanId, ['amount' => $amount, 'term' => $termMonths]);

        // Solo alertas a inversores (sin auto-fondeo — BCRA PSCPP)
        try {
            (new AutoInvestService())->allocateForLoan($loanId);
        } catch (\Throwable $e) {
            error_log('autoinvest on create: ' . $e->getMessage());
        }

        return $loanId;
    }

    public function fund(int $loanId, int $lenderId, float $amount): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $loan = $db->fetch('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$loanId]);
            if (!$loan || !in_array($loan['status'], ['open', 'funding'], true)) {
                throw new RuntimeException('Este crédito no acepta fondeo.');
            }
            if ((int) $loan['borrower_id'] === $lenderId) {
                throw new RuntimeException('No podés fondear tu propio crédito.');
            }

            $lender = $db->fetch('SELECT * FROM users WHERE id = ?', [$lenderId]);
            if (!$lender || !(int) $lender['can_lend']) {
                throw new RuntimeException('No tenés permiso para otorgar créditos.');
            }
            if (($lender['kyc_status'] ?? '') !== 'approved' && ($lender['role'] ?? '') !== 'admin') {
                throw new RuntimeException('Verificá tu identidad para invertir / otorgar créditos.');
            }

            $remaining = round((float) $loan['principal'] - (float) $loan['funded_amount'], 2);
            if ($amount <= 0 || $amount > $remaining) {
                throw new RuntimeException('Monto de fondeo inválido. Restante: ' . money($remaining));
            }

            $this->wallet->reserve($lenderId, $amount);

            $share = $amount / (float) $loan['principal'];
            // Neto de comisión: la originación la cobra Credimax, no el prestamista.
            $lendersTotal = max(0.0, (float) $loan['total_payable'] - (float) $loan['origination_fee_amount']);
            $expected = round($lendersTotal * $share, 2);

            $db->insert('loan_fundings', [
                'loan_id' => $loanId,
                'lender_id' => $lenderId,
                'amount' => $amount,
                'expected_return' => $expected,
                'status' => 'reserved',
                'funding_source' => 'manual',
            ]);

            $newFunded = round((float) $loan['funded_amount'] + $amount, 2);
            $status = $newFunded + 0.009 >= (float) $loan['principal'] ? 'funded' : 'funding';

            $db->update('loans', [
                'funded_amount' => $newFunded,
                'status' => $status,
                'funded_at' => $status === 'funded' ? date('Y-m-d H:i:s') : $loan['funded_at'],
            ], 'id = ?', [$loanId]);

            notify((int) $loan['borrower_id'], 'Nuevo fondeo', 'Recibiste ' . money($amount) . ' en tu solicitud ' . $loan['loan_code'], url('/loans/' . $loanId));
            audit_log('loan.fund', 'loan', (string) $loanId, ['lender' => $lenderId, 'amount' => $amount]);

            if ($status === 'funded') {
                $this->disburse($loanId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function disburse(int $loanId): void
    {
        $db = App::db();
        $loan = $db->fetch('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$loanId]);
        if (!$loan || $loan['status'] !== 'funded') {
            // puede llamarse dentro de la misma transacción tras marcar funded
            if (!$loan || !in_array($loan['status'], ['funded', 'funding'], true)) {
                throw new RuntimeException('El crédito no está listo para desembolso.');
            }
            if ((float) $loan['funded_amount'] + 0.009 < (float) $loan['principal']) {
                throw new RuntimeException('Fondeo incompleto.');
            }
        }

        $fundings = $db->fetchAll('SELECT * FROM loan_fundings WHERE loan_id = ? AND status = ? FOR UPDATE', [$loanId, 'reserved']);
        foreach ($fundings as $f) {
            $this->wallet->consumeReserve(
                (int) $f['lender_id'],
                (float) $f['amount'],
                'loan_fund',
                'Fondeo crédito ' . $loan['loan_code'],
                $loanId,
                (int) $loan['borrower_id']
            );
            $db->update('loan_fundings', ['status' => 'active'], 'id = ?', [(int) $f['id']]);
        }

        $feePct = (float) ($db->fetch('SELECT origination_fee_pct FROM loan_products WHERE id = ?', [(int) $loan['product_id']])['origination_fee_pct'] ?? 0);
        $fee = round((float) $loan['principal'] * $feePct / 100, 2);
        // El cliente siempre recibe el monto pedido; la comisión ya está financiada en las cuotas.
        $disbursement = (float) $loan['principal'];

        $this->wallet->credit(
            (int) $loan['borrower_id'],
            $disbursement,
            'loan_disburse',
            'Desembolso crédito ' . $loan['loan_code'] . ($fee > 0 ? " (monto completo; comisión {$feePct}% financiada en cuotas)" : ''),
            $loanId
        );

        $db->update('loans', [
            'status' => 'active',
            'disbursed_at' => date('Y-m-d H:i:s'),
            'funded_amount' => $loan['principal'],
            'funded_at' => $loan['funded_at'] ?: date('Y-m-d H:i:s'),
        ], 'id = ?', [$loanId]);

        notify((int) $loan['borrower_id'], 'Crédito desembolsado', 'Se acreditaron ' . money($disbursement) . ' en tu billetera (monto solicitado completo).', url('/wallet'));
        foreach ($fundings as $f) {
            notify((int) $f['lender_id'], 'Inversión activada', 'Tu fondeo en ' . $loan['loan_code'] . ' está activo.', url('/investments'));
        }
        audit_log('loan.disburse', 'loan', (string) $loanId);
    }

    public function payInstallment(int $loanId, int $payerId, ?int $installmentId = null): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $loan = $db->fetch('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$loanId]);
            if (!$loan || $loan['status'] !== 'active') {
                throw new RuntimeException('El crédito no está activo.');
            }
            if ((int) $loan['borrower_id'] !== $payerId) {
                throw new RuntimeException('Solo el deudor puede pagar las cuotas.');
            }

            if ($installmentId) {
                $inst = $db->fetch('SELECT * FROM loan_installments WHERE id = ? AND loan_id = ? FOR UPDATE', [$installmentId, $loanId]);
            } else {
                $inst = $db->fetch(
                    "SELECT * FROM loan_installments WHERE loan_id = ? AND status IN ('pending','partial','overdue') ORDER BY installment_number ASC LIMIT 1 FOR UPDATE",
                    [$loanId]
                );
            }
            if (!$inst) {
                throw new RuntimeException('No hay cuotas pendientes.');
            }

            $due = round((float) $inst['total_amount'] - (float) $inst['paid_amount'], 2);
            $this->wallet->debit($payerId, $due, 'loan_repay', 'Pago cuota #' . $inst['installment_number'] . ' ' . $loan['loan_code'], $loanId);

            $payRef = generate_reference('PAY');
            $db->insert('loan_payments', [
                'loan_id' => $loanId,
                'installment_id' => (int) $inst['id'],
                'payer_id' => $payerId,
                'amount' => $due,
                'payment_method' => 'wallet',
                'reference' => $payRef,
            ]);

            $db->update('loan_installments', [
                'paid_amount' => $inst['total_amount'],
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $inst['id']]);

            // La comisión de originación financiada en la cuota es ingreso de Credimax,
            // no rendimiento del prestamista: se separa antes de repartir.
            $feePortion = min($due, max(0.0, round((float) $inst['fee_portion'], 2)));
            $lendersPool = round($due - $feePortion, 2);

            $fundings = $db->fetchAll("SELECT * FROM loan_fundings WHERE loan_id = ? AND status = 'active'", [$loanId]);
            $funded = 0.0;
            foreach ($fundings as $f) {
                $funded += (float) $f['amount'];
            }
            if ($funded <= 0) {
                throw new RuntimeException('El crédito no tiene fondeo activo para acreditar.');
            }

            // Reparto por resto mayor: repartir con round() suelto deja o inventa centavos
            // en cada cuota, y ese desvío rompe la conciliación del ledger.
            $payouts = [];
            $assigned = 0.0;
            foreach ($fundings as $f) {
                $portion = round($lendersPool * ((float) $f['amount'] / $funded), 2);
                $payouts[(int) $f['id']] = $portion;
                $assigned = round($assigned + $portion, 2);
            }
            $residual = round($lendersPool - $assigned, 2);
            if (abs($residual) >= 0.01 && $payouts !== []) {
                $biggestId = 0;
                $biggestAmount = -1.0;
                foreach ($fundings as $f) {
                    if ((float) $f['amount'] > $biggestAmount) {
                        $biggestAmount = (float) $f['amount'];
                        $biggestId = (int) $f['id'];
                    }
                }
                $payouts[$biggestId] = round($payouts[$biggestId] + $residual, 2);
            }

            foreach ($fundings as $f) {
                $portion = $payouts[(int) $f['id']] ?? 0.0;
                if ($portion > 0) {
                    $this->wallet->credit(
                        (int) $f['lender_id'],
                        $portion,
                        'loan_repay',
                        'Cobro cuota #' . $inst['installment_number'] . ' ' . $loan['loan_code'],
                        $loanId,
                        $payerId
                    );
                    notify((int) $f['lender_id'], 'Cobro recibido', 'Recibiste ' . money($portion) . ' del crédito ' . $loan['loan_code'], url('/investments'));
                }
            }

            if ($feePortion > 0) {
                (new TreasuryService($this->wallet))->recordPlatformRevenue(
                    $feePortion,
                    'Comisión originación ' . $loan['loan_code'] . ' cuota #' . $inst['installment_number'],
                    (int) $loan['borrower_id']
                );
            }

            $pending = $db->fetch(
                "SELECT COUNT(*) AS c FROM loan_installments WHERE loan_id = ? AND status IN ('pending','partial','overdue')",
                [$loanId]
            );
            if ((int) ($pending['c'] ?? 0) === 0) {
                $db->update('loans', [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'next_due_date' => null,
                ], 'id = ?', [$loanId]);
                $db->query("UPDATE loan_fundings SET status = 'completed' WHERE loan_id = ?", [$loanId]);
                notify($payerId, 'Crédito cancelado', 'Terminaste de pagar ' . $loan['loan_code'], url('/loans/' . $loanId));
            } else {
                $next = $db->fetch(
                    "SELECT due_date FROM loan_installments WHERE loan_id = ? AND status IN ('pending','partial','overdue') ORDER BY installment_number ASC LIMIT 1",
                    [$loanId]
                );
                $db->update('loans', ['next_due_date' => $next['due_date'] ?? null], 'id = ?', [$loanId]);
            }

            audit_log('loan.pay', 'loan', (string) $loanId, ['installment' => $inst['installment_number'], 'amount' => $due]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function markOverdue(): int
    {
        $db = App::db();
        $stmt = $db->query(
            "UPDATE loan_installments SET status = 'overdue'
             WHERE status IN ('pending','partial') AND due_date < CURDATE()"
        );
        $n = $stmt->rowCount();
        $db->query(
            "UPDATE loans l SET status = 'defaulted'
             WHERE l.status = 'active'
               AND EXISTS (SELECT 1 FROM loan_installments i WHERE i.loan_id = l.id AND i.status = 'overdue' AND DATEDIFF(CURDATE(), i.due_date) >= 30)"
        );
        return (int) $n;
    }

    public function applyLateFees(): int
    {
        $db = App::db();
        $updated = 0;
        $rows = $db->fetchAll(
            "SELECT i.*, p.late_fee_pct, p.annual_rate, l.loan_code, l.borrower_id, l.product_id
             FROM loan_installments i
             JOIN loans l ON l.id = i.loan_id
             JOIN loan_products p ON p.id = l.product_id
             WHERE i.status IN ('overdue','partial')
               AND (i.last_late_calc_date IS NULL OR i.last_late_calc_date < CURDATE())"
        );
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $due = strtotime((string) $r['due_date']);
            if ($due === false) {
                continue;
            }
            $todayTs = strtotime($today);
            $daysLate = max(0, (int) floor(($todayTs - $due) / 86400));
            if ($daysLate <= 0) {
                continue;
            }
            $owed = max(0.0, round((float) $r['total_amount'] - (float) $r['paid_amount'], 2));
            if ($owed <= 0.0001) {
                $db->update('loan_installments', [
                    'last_late_calc_date' => $today,
                ], 'id = ?', [(int) $r['id']]);
                continue;
            }
            $annualRate = max(0.0, (float) $r['annual_rate']);
            $lateFeePct = max(0.0, (float) ($r['late_fee_pct'] ?? 3.0));
            $dailyRate = (($annualRate / 100) * 3.0) / 365;
            $interestPunitorio = round($owed * $dailyRate * $daysLate, 2);
            $gastosCobranza = 0.0;
            if ($daysLate > 0) {
                $gastosCobranza = round($owed * $lateFeePct / 100, 2);
            }
            $prevLate = round((float) ($r['late_interest_amount'] ?? 0.0), 2);
            $newLate = round(max($prevLate, $interestPunitorio + $gastosCobranza), 2);
            if (abs($newLate - $prevLate) >= 0.01 || (string) ($r['last_late_calc_date'] ?? '') !== $today) {
                $db->update('loan_installments', [
                    'late_interest_amount' => $newLate,
                    'last_late_calc_date' => $today,
                ], 'id = ?', [(int) $r['id']]);
                $updated++;
                $prevMeta = @json_decode((string) ($r['meta'] ?? ''), true) ?: [];
                $prevMeta['late_breakdown'] = [
                    'days' => $daysLate,
                    'owed' => $owed,
                    'interest_punitorio' => $interestPunitorio,
                    'gastos_cobranza' => $gastosCobranza,
                    'total' => $newLate,
                    'as_of' => $today,
                ];
                $db->query(
                    "UPDATE loan_installments SET meta = ? WHERE id = ?",
                    [json_encode($prevMeta, JSON_UNESCAPED_UNICODE), (int) $r['id']]
                );
                if ($daysLate === 1 || $daysLate % 15 === 0) {
                    $uid = (int) $r['borrower_id'];
                    notify($uid, 'Cuota vencida', 'Tu cuota #' . ((int) $r['installment_number']) . ' del crédito ' . ((string) $r['loan_code']) . ' tiene ' . $daysLate . ' días de atraso. Regularizá tu situación para no aumentar los intereses punitorios.', url('/loans/' . ((int) $r['loan_id'])));
                }
            }
        }
        return $updated;
    }

    public function sendInstallmentReminders(): int
    {
        $db = App::db();
        $sent = 0;
        $rows = $db->fetchAll(
            "SELECT i.*, l.loan_code, l.borrower_id, u.email, u.first_name, u.last_name
             FROM loan_installments i
             JOIN loans l ON l.id = i.loan_id
             JOIN users u ON u.id = l.borrower_id
             WHERE i.status IN ('pending','partial')
               AND DATEDIFF(i.due_date, CURDATE()) BETWEEN 0 AND 4"
        );
        foreach ($rows as $r) {
            $uid = (int) $r['borrower_id'];
            $dueTs = (int) strtotime((string) $r['due_date']);
            $diffSec = $dueTs - time();
            $days = max(0, (int) floor($diffSec / 86400));
            if ($days <= 0) {
                $t = 'Tu cuota vence hoy';
            } else {
                $t = 'Tu cuota vence en ' . $days . ' días';
            }
            notify($uid, $t, 'Cuota #' . ((int) $r['installment_number']) . ' del crédito ' . ((string) $r['loan_code']) . ' por ' . money((float) $r['total_amount']) . '.', url('/loans/' . ((int) $r['loan_id'])));
            try {
                $email = (string) ($r['email'] ?? '');
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
                    $mailSvc = new MailService();
                    $html = '<p>Hola ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p>Tenés un recordatorio de Credimax: tu cuota #' . ((int) $r['installment_number']) . ' del crédito <strong>' . htmlspecialchars((string) $r['loan_code'], ENT_QUOTES, 'UTF-8') . '</strong> '
                        . ($days <= 0 ? 'vence HOY' : 'vence en ' . $days . ' días')
                        . ' por un importe de <strong>' . htmlspecialchars(money((float) $r['total_amount']), ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                        . '<p>Ingresá a Credimax para regularizar tu situación y evitar intereses punitorios.</p>'
                        . '<p>Saludos,<br>Equipo Credimax</p>';
                    $mailSvc->send($email, 'Recordatorio vencimiento cuota ' . $r['loan_code'] . ' #' . $r['installment_number'], $html);
                    $sent++;
                }
            } catch (\Throwable $e) {
                error_log('reminder: ' . $e->getMessage());
            }
        }
        return $sent;
    }
}
