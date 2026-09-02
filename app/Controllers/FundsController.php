<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\TreasuryService;
use App\Services\WalletService;

/** Depósitos a Credimax + mandato de inversión (manual / auto). */
final class FundsController
{
    public function index(): void
    {
        require_auth();
        $wallet = (new WalletService())->ensureWallet(auth_id());
        $mandate = (new TreasuryService())->getMandate(auth_id());
        $deposits = App::db()->fetchAll(
            'SELECT * FROM fund_deposits WHERE user_id = ? ORDER BY id DESC LIMIT 30',
            [auth_id()]
        );
        $exposure = App::db()->fetch(
            "SELECT COALESCE(SUM(amount),0) s FROM loan_fundings WHERE lender_id = ? AND status IN ('reserved','active')",
            [auth_id()]
        );
        View::render('funds/index', [
            'title' => 'Mis fondos en Credimax',
            'wallet' => $wallet,
            'mandate' => $mandate,
            'deposits' => $deposits,
            'exposure' => (float) ($exposure['s'] ?? 0),
        ]);
    }

    public function requestDeposit(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            (new TreasuryService())->requestDeposit(
                auth_id(),
                $amount,
                (string) ($_POST['method'] ?? 'transfer'),
                trim((string) ($_POST['external_reference'] ?? ''))
            );
            Session::flash('success', 'Depósito informado. Credimax lo confirmará al recibir los fondos.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/funds'));
    }

    public function saveMandate(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            if ((auth_user()['kyc_status'] ?? '') !== 'approved' && !is_admin()) {
                throw new \RuntimeException('Necesitás KYC aprobado para configurar el mandato.');
            }
            (new TreasuryService())->saveMandate(auth_id(), [
                'mode' => $_POST['mode'] ?? 'manual',
                'max_per_loan' => $_POST['max_per_loan'] ?? 50000,
                'max_total_exposure' => $_POST['max_total_exposure'] ?? 500000,
                'min_annual_rate' => $_POST['min_annual_rate'] ?? 0,
                'allowed_bands' => $_POST['allowed_bands'] ?? 'A,B,C',
                'active' => 1,
            ]);
            Session::flash('success', 'Mandato actualizado.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/funds'));
    }
}
