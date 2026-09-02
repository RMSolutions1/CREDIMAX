<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\LoanService;

final class LoanController
{
    public function marketplace(): void
    {
        require_auth();
        $db = App::db();
        $minAmount = parse_amount((string) ($_GET['monto_min'] ?? '0'));
        $maxAmount = parse_amount((string) ($_GET['monto_max'] ?? '9999999999'));
        $minRate = (float) ($_GET['tna_min'] ?? 0.0);
        $maxRate = (float) ($_GET['tna_max'] ?? 999.0);
        $maxTerm = (int) ($_GET['plazo_max_meses'] ?? 999);
        $band = trim((string) ($_GET['riesgo'] ?? ''));
        $purpose = trim((string) ($_GET['proposito'] ?? ''));
        $order = in_array($_GET['orden'] ?? 'reciente', ['tna_asc','tna_desc','plazo_asc','monto_desc','reciente'], true)
            ? (string) $_GET['orden']
            : 'reciente';
        $orderMap = [
            'tna_asc' => 'l.annual_rate ASC',
            'tna_desc' => 'l.annual_rate DESC',
            'plazo_asc' => 'l.term_months ASC',
            'monto_desc' => 'l.principal DESC',
            'reciente' => 'l.created_at DESC',
        ];
        $wheres = [];
        $args = [];
        $wheres[] = "l.status IN ('open','funding')";
        if ($minAmount > 0) { $wheres[] = 'l.principal >= ?'; $args[] = $minAmount; }
        if ($maxAmount > 0) { $wheres[] = 'l.principal <= ?'; $args[] = $maxAmount; }
        if ($minRate > 0) { $wheres[] = 'l.annual_rate >= ?'; $args[] = $minRate; }
        if ($maxRate < 999) { $wheres[] = 'l.annual_rate <= ?'; $args[] = $maxRate; }
        if ($maxTerm < 999) { $wheres[] = 'l.term_months <= ?'; $args[] = $maxTerm; }
        if ($band !== '') { $wheres[] = 'u.risk_band = ?'; $args[] = strtoupper($band); }
        if ($purpose !== '') { $wheres[] = 'l.purpose LIKE ?'; $args[] = '%' . $purpose . '%'; }
        $whereSql = $wheres === [] ? '' : 'WHERE ' . implode(' AND ', $wheres);
        $loans = $db->fetchAll(
            "SELECT l.*, u.first_name, u.last_name, u.credimax_id, u.risk_band, p.name AS product_name
             FROM loans l
             JOIN users u ON u.id = l.borrower_id
             JOIN loan_products p ON p.id = l.product_id
             {$whereSql}
             ORDER BY {$orderMap[$order]}"
            , $args
        );
        View::render('loans/marketplace', [
            'title' => 'Mercado de créditos',
            'loans' => $loans,
            'filters' => [
                'monto_min' => $minAmount, 'monto_max' => $maxAmount,
                'tna_min' => $minRate, 'tna_max' => $maxRate,
                'plazo_max_meses' => $maxTerm, 'riesgo' => $band,
                'proposito' => $purpose, 'orden' => $order,
            ],
        ]);
    }

    public function myLoans(): void
    {
        require_auth();
        $loans = App::db()->fetchAll(
            'SELECT l.*, p.name AS product_name FROM loans l JOIN loan_products p ON p.id = l.product_id WHERE l.borrower_id = ? ORDER BY l.id DESC',
            [auth_id()]
        );
        View::render('loans/mine', ['title' => 'Mis créditos', 'loans' => $loans]);
    }

    public function investments(): void
    {
        require_auth();
        $uid = auth_id();
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT f.*, l.loan_code, l.status AS loan_status, l.annual_rate, l.principal, l.term_months,
                    l.risk_band, l.risk_score, u.credimax_id AS borrower_id_code
             FROM loan_fundings f
             JOIN loans l ON l.id = f.loan_id
             JOIN users u ON u.id = l.borrower_id
             WHERE f.lender_id = ?
             ORDER BY f.id DESC',
            [$uid]
        );

        $investedTotal = 0.0;
        $earnedTotal = 0.0;
        $pendingTotal = 0.0;
        $rateSum = 0.0;
        $rateCount = 0;
        $bandDist = [];
        foreach ($rows as $r) {
            $investedTotal += (float) $r['amount'];
            $earnedTotal += (float) ($r['amount_repaid'] ?? 0);
            $pendingTotal += (float) ($r['amount_pending'] ?? 0);
            if (!empty($r['annual_rate'])) { $rateSum += (float) $r['annual_rate']; $rateCount++; }
            $b = (string) ($r['risk_band'] ?? 'NR');
            $bandDist[$b] = ($bandDist[$b] ?? 0) + (float) $r['amount'];
        }

        $avgTna = $rateCount > 0 ? $rateSum / $rateCount : 0.0;
        $roiAnualizado = $investedTotal > 0 && $avgTna > 0 ? $avgTna : 0.0;

        $proximos30 = $db->fetchAll(
            "SELECT i.id, i.due_date, i.installment_number, i.amount_total, l.loan_code,
                    COALESCE(u.first_name,'') 'Nombre', COALESCE(u.last_name,'') 'Apellido'
             FROM loan_installments i
             JOIN loans l ON l.id = i.loan_id
             JOIN loan_fundings f ON f.loan_id = l.id
             LEFT JOIN users u ON u.id = l.borrower_id
             WHERE f.lender_id = ? AND i.status = 'pending'
               AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY i.due_date ASC LIMIT 8",
            [$uid]
        );

        $summary = [
            'invested' => $investedTotal,
            'earned' => $earnedTotal,
            'pending' => $pendingTotal,
            'avg_tna' => $avgTna,
            'roi_anual' => $roiAnualizado,
            'band_dist' => $bandDist,
            'oper_count' => count($rows),
        ];

        View::render('loans/investments', [
            'title' => 'Mis inversiones',
            'rows' => $rows,
            'summary' => $summary,
            'proximos30' => $proximos30,
        ]);
    }

    public function exportMyAfipRentas(): void
    {
        require_auth();
        $uid = auth_id();
        $year = (int) ($_GET['year'] ?? (int) date('Y'));
        $u = auth_user();
        $db = App::db();

        $rows = $db->fetchAll(
            "SELECT i.due_date, i.paid_at, i.installment_number, i.amount_total,
                    i.amount_principal, i.amount_interest, i.amount_late_fee,
                    l.loan_code, l.annual_rate, b.credimax_id deudor_code,
                    CONCAT(b.first_name,' ',b.last_name) deudor_nombre
             FROM loan_installments i
             JOIN loans l ON l.id = i.loan_id
             JOIN users b ON b.id = l.borrower_id
             WHERE i.status = 'paid'
               AND EXISTS (SELECT 1 FROM loan_fundings f WHERE f.loan_id = l.id AND f.lender_id = ?)
               AND YEAR(i.paid_at) = ?
             ORDER BY i.paid_at ASC",
            [$uid, $year]
        );

        $bruto = 0.0;
        $out = [];
        foreach ($rows as $r) {
            $interes = (float) $r['amount_interest'] + (float) $r['amount_late_fee'];
            $bruto += $interes;
            $out[] = [
                $year, (string) ($r['paid_at'] ?? ''), (string) ($r['loan_code'] ?? ''),
                (int) $r['installment_number'], (string) ($r['deudor_code'] ?? ''),
                trim((string) ($r['deudor_nombre'] ?? '')),
                number_format((float) $r['amount_principal'], 2, ',', ''),
                number_format($interes, 2, ',', ''),
                number_format((float) $r['amount_total'], 2, ',', ''),
            ];
        }
        $iva = $bruto * 0.035;
        $gan = $bruto * 0.06;
        array_unshift($out, ['RESUMEN EJERCICIO '.$year,'','','','','','',
            'InteresesBrutos=' . number_format($bruto,2,',','.'),
            'RetencionIVA=' . number_format($iva,2,',','.'),
            'RetencionGanancias=' . number_format($gan,2,',','.')]);

        csv_emit(
            'afip-mis-rentas-' . $u['credimax_id'] . '-' . $year . '.csv',
            $out,
            ['Ejercicio','FechaCobro','Préstamo','Cuota','DeudorCredimax','DeudorNombre',
                'CapitalCobrado','InteresesCobrados','CobroTotal']
        );
    }

    public function createForm(): void
    {
        require_auth();
        $products = App::db()->fetchAll('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY id');
        View::render('loans/create', ['title' => 'Solicitar crédito', 'products' => $products]);
    }

    public function create(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            if (empty($_POST['accept_contract'])) {
                throw new \RuntimeException('Debés confirmar que revisaste cuota, TNA y CFT.');
            }
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            $id = (new LoanService())->createRequest(
                auth_id(),
                (int) ($_POST['product_id'] ?? 0),
                $amount,
                (int) ($_POST['term_months'] ?? 0),
                trim((string) ($_POST['purpose'] ?? ''))
            );
            Session::flash('success', 'Solicitud publicada en el mercado P2P.');
            redirect(url('/loans/' . $id));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/loans/create'));
        }
    }

    public function show(string $id): void
    {
        require_auth();
        $loanId = (int) $id;
        $loan = App::db()->fetch(
            'SELECT l.*, p.name AS product_name, u.first_name, u.last_name, u.credimax_id, u.risk_band, u.account_type
             FROM loans l
             JOIN loan_products p ON p.id = l.product_id
             JOIN users u ON u.id = l.borrower_id
             WHERE l.id = ?',
            [$loanId]
        );
        if (!$loan) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'No encontrado']);
            return;
        }
        $installments = App::db()->fetchAll(
            'SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_number',
            [$loanId]
        );
        $fundings = App::db()->fetchAll(
            'SELECT f.*, u.credimax_id, u.first_name FROM loan_fundings f JOIN users u ON u.id = f.lender_id WHERE f.loan_id = ? ORDER BY f.id',
            [$loanId]
        );
        $remaining = round((float) $loan['principal'] - (float) $loan['funded_amount'], 2);

        View::render('loans/show', [
            'title' => $loan['loan_code'],
            'loan' => $loan,
            'installments' => $installments,
            'fundings' => $fundings,
            'remaining' => $remaining,
        ]);
    }

    public function fund(string $id): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            (new LoanService())->fund((int) $id, auth_id(), $amount);
            Session::flash('success', 'Fondeo registrado correctamente.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/loans/' . (int) $id));
    }

    public function pay(string $id): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $instId = !empty($_POST['installment_id']) ? (int) $_POST['installment_id'] : null;
            (new LoanService())->payInstallment((int) $id, auth_id(), $instId);
            Session::flash('success', 'Cuota pagada correctamente.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/loans/' . (int) $id));
    }

    public function verifyContract(string $hash): void
    {
        $row = \App\Core\ContractSigner::verifyHash($hash);
        if ($row === null) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Contrato no encontrado']);
            return;
        }
        $user = App::db()->fetch('SELECT first_name, last_name, email, document_type, document_number FROM users WHERE id = ?', [(int) ($row['user_id'] ?? 0)]);
        View::render('contracts/verify', [
            'title' => 'Verificación de contrato',
            'contract' => $row,
            'user' => $user,
        ]);
    }

    public function downloadContract(string $id): void
    {
        require_auth();
        $loanId = (int) $id;
        $loan = App::db()->fetch('SELECT * FROM loans WHERE id = ?', [$loanId]);
        if (!$loan) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'No encontrado']);
            return;
        }
        $uid = auth_id();
        $isBorrower = (int) $loan['borrower_id'] === $uid;
        $isLender = (bool) App::db()->fetch(
            'SELECT COUNT(*) c FROM loan_fundings WHERE loan_id = ? AND lender_id = ?',
            [$loanId, $uid]
        )['c'];
        $isAdmin = is_admin();
        if (!$isBorrower && !$isLender && !$isAdmin) {
            http_response_code(403);
            Session::flash('error', 'No tenés permiso para ver este contrato.');
            redirect(url('/dashboard'));
        }
        $borrower = App::db()->fetch('SELECT * FROM users WHERE id = ?', [(int) $loan['borrower_id']]);
        $product = App::db()->fetch('SELECT * FROM loan_products WHERE id = ?', [(int) $loan['product_id']]);
        $lenders = App::db()->fetchAll(
            'SELECT f.amount, f.created_at, u.email, u.first_name, u.last_name, u.credimax_id
             FROM loan_fundings f JOIN users u ON u.id = f.lender_id
             WHERE f.loan_id = ? AND f.status IN (\'reserved\',\'active\',\'completed\')
             ORDER BY f.id',
            [$loanId]
        );
        $html = \App\Core\ContractSigner::renderLoanContract($loan, $borrower ?: [], $lenders, $product ?: []);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="contrato-' . ($loan['loan_code'] ?? ('loan-' . $loanId)) . '.html"');
        echo $html;
    }
}
