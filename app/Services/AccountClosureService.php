<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/** Baja de cuenta y derecho de arrepentimiento (Ley 24.240). */
final class AccountClosureService
{
    public function requestRegret(int $userId, string $reason = ''): void
    {
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user || $user['status'] === 'closed') {
            throw new RuntimeException('Cuenta no válida.');
        }
        $activeLoans = (int) (App::db()->fetch(
            "SELECT COUNT(*) c FROM loans WHERE borrower_id = ? AND status IN ('open','funding','funded','active')",
            [$userId]
        )['c'] ?? 0);
        $activeFundings = (int) (App::db()->fetch(
            "SELECT COUNT(*) c FROM loan_fundings WHERE lender_id = ? AND status IN ('reserved','active')",
            [$userId]
        )['c'] ?? 0);
        if ($activeLoans > 0 || $activeFundings > 0) {
            throw new RuntimeException('Tenés operaciones activas. Cancelá/completé créditos o fondeos antes de ejercer el arrepentimiento sobre la cuenta.');
        }

        App::db()->update('users', [
            'regret_requested_at' => date('Y-m-d H:i:s'),
            'closure_reason' => substr(trim($reason) !== '' ? $reason : 'Arrepentimiento Ley 24.240', 0, 255),
        ], 'id = ?', [$userId]);

        $admins = App::db()->fetchAll("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        foreach ($admins as $a) {
            notify((int) $a['id'], 'Arrepentimiento solicitado', 'Usuario #' . $userId . ' ejerció botón de arrepentimiento.', url('/admin/users'));
        }
        audit_log('account.regret', 'user', (string) $userId, ['reason' => $reason]);
        notify($userId, 'Arrepentimiento registrado', 'Registramos tu solicitud. Soporte te contactará dentro de los plazos legales aplicables.', url('/profile'));
    }

    public function closeAccount(int $userId, string $reason = ''): void
    {
        $wallet = (new WalletService())->ensureWallet($userId);
        if ((float) $wallet['available_balance'] > 1 || (float) $wallet['reserved_balance'] > 0.01) {
            throw new RuntimeException('Retirá o transferí tu saldo disponible antes de dar de baja la cuenta.');
        }
        $activeLoans = (int) (App::db()->fetch(
            "SELECT COUNT(*) c FROM loans WHERE borrower_id = ? AND status IN ('open','funding','funded','active')",
            [$userId]
        )['c'] ?? 0);
        $activeFundings = (int) (App::db()->fetch(
            "SELECT COUNT(*) c FROM loan_fundings WHERE lender_id = ? AND status IN ('reserved','active')",
            [$userId]
        )['c'] ?? 0);
        if ($activeLoans > 0 || $activeFundings > 0) {
            throw new RuntimeException('No podés dar de baja la cuenta con créditos o inversiones activas.');
        }

        App::db()->update('users', [
            'status' => 'closed',
            'closed_at' => date('Y-m-d H:i:s'),
            'closure_reason' => substr(trim($reason) !== '' ? $reason : 'Baja voluntaria', 0, 255),
            'can_lend' => 0,
            'can_borrow' => 0,
        ], 'id = ?', [$userId]);
        audit_log('account.close', 'user', (string) $userId);
    }
}
