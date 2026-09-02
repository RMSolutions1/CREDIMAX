<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\View;
use App\Services\WalletService;

final class DashboardController
{
    public function index(): void
    {
        require_auth();
        $db = App::db();
        $uid = auth_id();
        $user = auth_user();

        // Admin: panel de administración
        if (($user['role'] ?? '') === 'admin') {
            redirect(url('/admin'));
        }

        $wallet = (new WalletService())->ensureWallet($uid);
        $myLoans = $db->fetchAll('SELECT * FROM loans WHERE borrower_id = ? ORDER BY id DESC LIMIT 5', [$uid]);
        $investments = $db->fetchAll(
            'SELECT f.*, l.loan_code, l.status AS loan_status, l.annual_rate, l.term_months, l.principal
             FROM loan_fundings f
             JOIN loans l ON l.id = f.loan_id
             WHERE f.lender_id = ?
             ORDER BY f.id DESC LIMIT 8',
            [$uid]
        );
        $openLoans = $db->fetchAll(
            "SELECT l.*, u.first_name, u.last_name, u.credimax_id, u.risk_band
             FROM loans l
             JOIN users u ON u.id = l.borrower_id
             WHERE l.status IN ('open','funding')
             ORDER BY l.created_at DESC LIMIT 6"
        );
        $unread = $db->fetch('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$uid]);
        $nextInstallment = $db->fetch(
            "SELECT i.*, l.loan_code FROM loan_installments i JOIN loans l ON l.id = i.loan_id
             WHERE l.borrower_id = ? AND i.status IN ('pending','partial','overdue')
             ORDER BY i.due_date ASC LIMIT 1",
            [$uid]
        );

        $investedActive = (float) ($db->fetch(
            "SELECT COALESCE(SUM(amount),0) s FROM loan_fundings WHERE lender_id = ? AND status IN ('reserved','active')",
            [$uid]
        )['s'] ?? 0);
        $expectedReturn = (float) ($db->fetch(
            "SELECT COALESCE(SUM(expected_return),0) s FROM loan_fundings WHERE lender_id = ? AND status IN ('reserved','active')",
            [$uid]
        )['s'] ?? 0);
        $borrowedActive = (float) ($db->fetch(
            "SELECT COALESCE(SUM(principal),0) s FROM loans WHERE borrower_id = ? AND status IN ('open','funding','funded','active')",
            [$uid]
        )['s'] ?? 0);

        $mode = ($_GET['modo'] ?? '') === 'inversor' ? 'inversor' : (($_GET['modo'] ?? '') === 'solicitante' ? 'solicitante' : 'ambos');
        $investorStats = null;
        if ((int) ($user['can_lend'] ?? 0) || $investedActive > 0.0001) {
            try {
                $investorStats = (new \App\Services\InvestorDashboardService())->portfolio($uid);
            } catch (\Throwable $e) {
                error_log('investor dashboard: ' . $e->getMessage());
            }
        }

        View::render('dashboard/index', [
            'title' => 'Mi panel',
            'wallet' => $wallet,
            'myLoans' => $myLoans,
            'investments' => $investments,
            'openLoans' => $openLoans,
            'unread' => (int) ($unread['c'] ?? 0),
            'nextInstallment' => $nextInstallment,
            'investedActive' => $investedActive,
            'expectedReturn' => $expectedReturn,
            'borrowedActive' => $borrowedActive,
            'mode' => $mode,
            'investorStats' => $investorStats,
        ]);
    }
}
