<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Preselección de oportunidades para inversores.
 * Bajo norma BCRA PSCPP el inversor debe conservar la decisión de otorgar cada crédito:
 * este servicio NO fondea automáticamente ni usa capital propio de la plataforma.
 */
final class AutoInvestService
{
    public function __construct(
        private ?LoanService $loans = null,
        private ?TreasuryService $treasury = null,
    ) {
        $this->loans = $loans ?? new LoanService();
        $this->treasury = $treasury ?? new TreasuryService();
    }

    /**
     * Notifica a inversores con mandato "auto" (alertas) sin ejecutar fondeo.
     * El fondeo efectivo requiere aprobación manual en el mercado.
     */
    public function allocateForLoan(int $loanId): array
    {
        $db = App::db();
        $loan = $db->fetch('SELECT l.*, u.risk_band FROM loans l JOIN users u ON u.id = l.borrower_id WHERE l.id = ?', [$loanId]);
        if (!$loan || !in_array($loan['status'], ['open', 'funding'], true)) {
            return ['funded' => 0, 'actions' => [], 'mode' => 'alerts_only'];
        }

        $remaining = round((float) $loan['principal'] - (float) $loan['funded_amount'], 2);
        if ($remaining <= 0) {
            return ['funded' => 0, 'actions' => [], 'mode' => 'alerts_only'];
        }

        $band = strtoupper((string) ($loan['risk_band'] ?? 'C'));
        $rate = (float) $loan['annual_rate'];
        $actions = [];

        $mandates = $db->fetchAll(
            "SELECT m.*, u.id AS uid
             FROM lender_mandates m
             JOIN users u ON u.id = m.user_id
             WHERE m.mode = 'auto' AND m.active = 1
               AND u.status = 'active' AND u.can_lend = 1 AND u.kyc_status = 'approved'
               AND u.id <> ?
             ORDER BY m.updated_at DESC
             LIMIT 50",
            [(int) $loan['borrower_id']]
        );

        foreach ($mandates as $m) {
            $bands = array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $m['allowed_bands']))));
            if ($bands && $band !== '' && $band !== '0' && !in_array($band, $bands, true)) {
                continue;
            }
            if ($rate + 0.0001 < (float) $m['min_annual_rate']) {
                continue;
            }
            $chunk = min($remaining, (float) $m['max_per_loan']);
            if ($chunk < 100) {
                continue;
            }
            notify(
                (int) $m['uid'],
                'Oportunidad de inversión',
                'Hay un crédito ' . $loan['loan_code'] . ' compatible con tu perfil. Debés aprobar el fondeo manualmente (normativa BCRA).',
                url('/loans/' . $loanId)
            );
            $actions[] = ['lender_id' => (int) $m['uid'], 'amount' => $chunk, 'source' => 'alert_only'];
        }

        // Capital propio de la plataforma: deshabilitado (PSCPP no puede ser oferente).
        return ['funded' => 0, 'actions' => $actions, 'mode' => 'alerts_only'];
    }
}
