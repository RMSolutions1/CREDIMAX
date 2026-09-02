<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Estadísticas públicas agregadas (estilo home Afluenta / PSCPP). */
final class StatsService
{
    public function publicSnapshot(): array
    {
        $db = App::db();
        $funded = $db->fetch(
            "SELECT COALESCE(SUM(principal),0) s, COUNT(*) c
             FROM loans WHERE status IN ('active','completed','funded','defaulted')"
        );
        $interest = $db->fetch(
            "SELECT COALESCE(SUM(interest_portion),0) s
             FROM loan_installments WHERE status = 'paid'"
        );
        $open = (int) ($db->fetch(
            "SELECT COUNT(*) c FROM loans WHERE status IN ('open','funding')"
        )['c'] ?? 0);
        $investors = (int) ($db->fetch(
            "SELECT COUNT(DISTINCT lender_id) c FROM loan_fundings WHERE status IN ('active','completed','reserved')"
        )['c'] ?? 0);
        $borrowers = (int) ($db->fetch(
            "SELECT COUNT(DISTINCT borrower_id) c FROM loans WHERE status IN ('active','completed','funded','open','funding')"
        )['c'] ?? 0);
        $overdue = (int) ($db->fetch(
            "SELECT COUNT(*) c FROM loan_installments WHERE status = 'overdue'"
        )['c'] ?? 0);
        $pendingInst = (int) ($db->fetch(
            "SELECT COUNT(*) c FROM loan_installments WHERE status IN ('pending','partial','overdue')"
        )['c'] ?? 0);

        $byBand = $db->fetchAll(
            "SELECT COALESCE(u.risk_band,'—') band, COUNT(*) c, COALESCE(SUM(l.principal),0) amount
             FROM loans l
             JOIN users u ON u.id = l.borrower_id
             WHERE l.status IN ('active','completed','funded','open','funding')
             GROUP BY COALESCE(u.risk_band,'—')
             ORDER BY c DESC"
        );

        $volume = (float) ($funded['s'] ?? 0);
        $count = (int) ($funded['c'] ?? 0);
        $interestPaid = (float) ($interest['s'] ?? 0);

        return [
            'volume_credits' => $volume,
            'loans_granted' => $count,
            'interest_paid' => $interestPaid,
            'open_requests' => $open,
            'investors' => $investors,
            'borrowers' => $borrowers,
            'overdue_ratio' => $pendingInst > 0 ? round(($overdue / $pendingInst) * 100, 1) : 0.0,
            'by_band' => $byBand,
            'updated_at' => date('c'),
        ];
    }
}
