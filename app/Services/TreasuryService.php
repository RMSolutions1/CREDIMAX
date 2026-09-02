<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * Tesorería Credimax: fondos propios + AUM de terceros (depósitos de prestamistas).
 * El admin acredita/debita como fintech/neobank; los prestamistas depositan a Credimax.
 */
final class TreasuryService
{
    public function __construct(private ?WalletService $wallet = null)
    {
        $this->wallet = $wallet ?? new WalletService();
    }

    public function snapshot(): array
    {
        $row = App::db()->fetch('SELECT * FROM platform_treasury WHERE id = 1');
        if (!$row) {
            App::db()->insert('platform_treasury', [
                'id' => 1,
                'own_balance' => 0,
                'third_party_aum' => 0,
                'pending_deposits' => 0,
            ]);
            $row = App::db()->fetch('SELECT * FROM platform_treasury WHERE id = 1');
        }
        $pending = (float) (App::db()->fetch(
            "SELECT COALESCE(SUM(amount),0) s FROM fund_deposits WHERE status = 'pending'"
        )['s'] ?? 0);
        $customerWallets = (float) (App::db()->fetch(
            "SELECT COALESCE(SUM(w.balance),0) s FROM wallets w JOIN users u ON u.id = w.user_id WHERE u.role = 'user'"
        )['s'] ?? 0);
        return [
            'own_balance' => (float) $row['own_balance'],
            'third_party_aum' => (float) $row['third_party_aum'],
            'pending_deposits' => $pending,
            'customer_wallets_total' => $customerWallets,
            'total_under_management' => (float) $row['own_balance'] + (float) $row['third_party_aum'],
        ];
    }

    public function requestDeposit(int $userId, float $amount, string $method = 'transfer', string $extRef = ''): int
    {
        $min = (float) App::config('wallet.min_deposit', 100);
        $max = (float) App::config('wallet.max_deposit', 2000000);
        if ($amount < $min || $amount > $max) {
            throw new RuntimeException('Monto de depósito fuera de límites.');
        }
        $id = App::db()->insert('fund_deposits', [
            'user_id' => $userId,
            'amount' => $amount,
            'method' => in_array($method, ['transfer', 'cash', 'admin', 'other'], true) ? $method : 'transfer',
            'external_reference' => $extRef !== '' ? substr($extRef, 0, 80) : null,
            'status' => 'pending',
        ]);
        notify($userId, 'Depósito pendiente', 'Tu depósito de ' . money($amount) . ' espera confirmación de Credimax.', url('/funds'));
        // notify admins
        $admins = App::db()->fetchAll("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        foreach ($admins as $a) {
            notify((int) $a['id'], 'Nuevo depósito pendiente', 'Usuario #' . $userId . ' depositó ' . money($amount), url('/admin/funds'));
        }
        audit_log('funds.deposit_request', 'fund_deposit', (string) $id, ['amount' => $amount]);
        return $id;
    }

    public function confirmDeposit(int $depositId, int $adminId, string $notes = ''): void
    {
        $db = App::db();
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
        try {
            $dep = $db->fetch('SELECT * FROM fund_deposits WHERE id = ? FOR UPDATE', [$depositId]);
            if (!$dep || $dep['status'] !== 'pending') {
                throw new RuntimeException('Depósito no pendiente.');
            }
            $amount = (float) $dep['amount'];
            $this->wallet->deposit((int) $dep['user_id'], $amount, 'Depósito confirmado Credimax #' . $depositId);

            $db->update('fund_deposits', [
                'status' => 'confirmed',
                'admin_id' => $adminId,
                'admin_notes' => $notes !== '' ? $notes : null,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$depositId]);

            $this->bumpAum($amount);
            $this->logAdmin($adminId, (int) $dep['user_id'], 'credit', $amount, 'customer', 'Confirmación depósito #' . $depositId);

            notify((int) $dep['user_id'], 'Depósito acreditado', 'Credimax acreditó ' . money($amount) . ' en tu billetera.', url('/wallet'));
            if ($ownsTx) {
                $db->commit();
            }
            audit_log('funds.deposit_confirm', 'fund_deposit', (string) $depositId);
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function rejectDeposit(int $depositId, int $adminId, string $notes = ''): void
    {
        $db = App::db();
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
        try {
            // Con bloqueo: sin esto, un rechazo concurrente puede pisar una confirmación
            // ya acreditada y dejar el saldo del usuario sin respaldo.
            $dep = $db->fetch('SELECT * FROM fund_deposits WHERE id = ? FOR UPDATE', [$depositId]);
            if (!$dep || $dep['status'] !== 'pending') {
                throw new RuntimeException('Depósito no pendiente.');
            }
            $db->update('fund_deposits', [
                'status' => 'rejected',
                'admin_id' => $adminId,
                'admin_notes' => $notes !== '' ? $notes : 'Rechazado',
                'confirmed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$depositId]);
            notify((int) $dep['user_id'], 'Depósito rechazado', $notes !== '' ? $notes : 'Tu depósito fue rechazado.', url('/funds'));
            if ($ownsTx) {
                $db->commit();
            }
            audit_log('funds.deposit_reject', 'fund_deposit', (string) $depositId);
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Ajuste admin +/- sobre billetera de usuario (estilo neobank). */
    public function adminAdjustBalance(int $adminId, int $targetUserId, float $amount, string $direction, string $reason, string $fundType = 'customer'): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Monto inválido.');
        }
        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw new RuntimeException('Dirección inválida.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Indicá el motivo del ajuste.');
        }

        $db = App::db();
        $db->beginTransaction();
        try {
            if ($direction === 'credit') {
                $result = $this->wallet->deposit($targetUserId, $amount, 'Ajuste admin (+): ' . $reason);
                if ($fundType === 'own') {
                    $this->addOwn($amount);
                } elseif ($fundType === 'customer') {
                    $this->bumpAum($amount);
                }
            } else {
                $result = ['reference' => $this->wallet->debit($targetUserId, $amount, 'adjustment', 'Ajuste admin (−): ' . $reason)];
                if ($fundType === 'own') {
                    $this->addOwn(-$amount);
                } elseif ($fundType === 'customer') {
                    $this->bumpAum(-$amount);
                }
            }

            $ref = generate_reference('ADM');
            $db->insert('admin_ledger', [
                'admin_id' => $adminId,
                'target_user_id' => $targetUserId,
                'direction' => $direction,
                'amount' => $amount,
                'fund_type' => $fundType,
                'reason' => substr($reason, 0, 255),
                'reference' => $ref,
            ]);

            notify($targetUserId, 'Movimiento de saldo',
                ($direction === 'credit' ? 'Se acreditaron ' : 'Se debitaron ') . money($amount) . '. Motivo: ' . $reason,
                url('/wallet'));

            $db->commit();
            audit_log('admin.balance_adjust', 'user', (string) $targetUserId, [
                'direction' => $direction,
                'amount' => $amount,
                'fund_type' => $fundType,
            ]);
            return ['reference' => $ref, 'wallet' => $result];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Inyectar capital propio de Credimax a la tesorería (no a un usuario). */
    public function injectOwnCapital(int $adminId, float $amount, string $reason): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Monto inválido.');
        }
        $this->addOwn($amount);
        App::db()->insert('admin_ledger', [
            'admin_id' => $adminId,
            'target_user_id' => $adminId,
            'direction' => 'credit',
            'amount' => $amount,
            'fund_type' => 'own',
            'reason' => 'Capital propio: ' . substr($reason, 0, 200),
            'reference' => generate_reference('OWN'),
        ]);
        audit_log('treasury.own_inject', 'platform_treasury', '1', ['amount' => $amount]);
    }

    /**
     * Recalcula el AUM de terceros contra la suma real de las billeteras de clientes.
     * Los ajustes manuales y los datos de prueba lo desfasan, y un AUM desfasado
     * hace que el panel informe un respaldo que no existe.
     *
     * @return array{before:float,after:float,delta:float}
     */
    public function recalcAum(int $adminId): array
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $this->ensureTreasuryRow();
            $row = $db->fetch('SELECT third_party_aum FROM platform_treasury WHERE id = 1 FOR UPDATE');
            $before = round((float) ($row['third_party_aum'] ?? 0), 2);
            $real = round((float) ($db->fetch(
                "SELECT COALESCE(SUM(w.balance),0) s FROM wallets w
                 JOIN users u ON u.id = w.user_id WHERE u.role = 'user'"
            )['s'] ?? 0), 2);

            $db->update('platform_treasury', ['third_party_aum' => $real], 'id = ?', [1]);
            $delta = round($real - $before, 2);
            if (abs($delta) >= 0.01) {
                $this->logAdmin($adminId, $adminId, $delta > 0 ? 'credit' : 'debit', abs($delta), 'aum_adjust', 'Recálculo de AUM contra billeteras');
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        audit_log('treasury.aum_recalc', 'platform_treasury', '1', ['before' => $before, 'after' => $real]);
        return ['before' => $before, 'after' => $real, 'delta' => $delta];
    }

    /**
     * Ingresos de la plataforma (comisiones de originación, spreads, cargos MP).
     * No mueve el AUM: ese dinero ya estaba dentro del sistema y sólo cambia de dueño.
     */
    public function recordPlatformRevenue(float $amount, string $concept, int $targetUserId = 0): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }
        $this->addOwn($amount);
        $this->bumpAum(-$amount);
        App::db()->insert('admin_ledger', [
            'admin_id' => 0,
            'target_user_id' => $targetUserId,
            'direction' => 'credit',
            'amount' => $amount,
            'fund_type' => 'own',
            'reason' => substr($concept, 0, 250),
            'reference' => generate_reference('REV'),
        ]);
    }

    public function requestWithdraw(int $userId, float $amount, string $cbu, string $alias, string $holder): int
    {
        $min = (float) App::config('wallet.min_withdraw', 100);
        $max = (float) App::config('wallet.max_withdraw', 2000000);
        if ($amount < $min || $amount > $max) {
            throw new RuntimeException('Monto de retiro fuera de límites.');
        }
        $cbu = preg_replace('/\D+/', '', $cbu) ?? '';
        $alias = strtolower(trim($alias));
        $holder = trim($holder);
        if (strlen($cbu) !== 22 && $alias === '') {
            throw new RuntimeException('Indicá CBU/CVU (22 dígitos) o alias bancario de destino.');
        }
        if (strlen($cbu) > 0 && strlen($cbu) !== 22) {
            throw new RuntimeException('CBU/CVU inválido (debe tener 22 dígitos).');
        }

        $wallet = $this->wallet->ensureWallet($userId);
        if ((float) $wallet['available_balance'] + 0.0001 < $amount) {
            throw new RuntimeException('Saldo disponible insuficiente para el retiro.');
        }

        $db = App::db();
        $db->beginTransaction();
        try {
            // Reserva: debit inmediato; el admin ejecuta la transferencia bancaria externa
            $this->wallet->debit($userId, $amount, 'withdraw', 'Retiro pendiente a cuenta bancaria');
            $id = $db->insert('withdraw_requests', [
                'user_id' => $userId,
                'amount' => $amount,
                'destination_cbu' => $cbu !== '' ? $cbu : null,
                'destination_alias' => $alias !== '' ? substr($alias, 0, 40) : null,
                'destination_holder' => $holder !== '' ? substr($holder, 0, 160) : null,
                'status' => 'pending',
            ]);
            $this->bumpAum(-$amount);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Si Mercado Pago está activo, encola el cash-out contra la cuenta madre.
        try {
            if ((new MercadoPagoService())->isEnabled()) {
                (new MpSubAccountService())->queuePayout($id);
            }
        } catch (\Throwable $e) {
            error_log('Cola MP payout: ' . $e->getMessage());
        }

        notify($userId, 'Retiro solicitado', 'Pediste retirar ' . money($amount) . '. Credimax lo procesará al confirmar la transferencia.', url('/wallet'));
        $admins = App::db()->fetchAll("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        foreach ($admins as $a) {
            notify((int) $a['id'], 'Nuevo retiro pendiente', 'Usuario #' . $userId . ' retira ' . money($amount), url('/admin/funds'));
        }
        audit_log('funds.withdraw_request', 'withdraw_request', (string) $id, ['amount' => $amount]);
        return $id;
    }

    public function markWithdrawPaid(int $withdrawId, int $adminId, string $notes = ''): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            // Con bloqueo: dos clics del admin generaban dos asientos en admin_ledger.
            $row = $db->fetch('SELECT * FROM withdraw_requests WHERE id = ? FOR UPDATE', [$withdrawId]);
            if (!$row || $row['status'] !== 'pending') {
                throw new RuntimeException('Retiro no pendiente.');
            }
            $db->update('withdraw_requests', [
                'status' => 'paid',
                'admin_id' => $adminId,
                'admin_notes' => $notes !== '' ? $notes : null,
                'paid_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$withdrawId]);
            $this->logAdmin($adminId, (int) $row['user_id'], 'debit', (float) $row['amount'], 'customer', 'Retiro pagado #' . $withdrawId);
            $this->closePayout($withdrawId, 'confirmed');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        notify((int) $row['user_id'], 'Retiro procesado', 'Credimax confirmó el pago de ' . money((float) $row['amount']) . ' a tu cuenta.', url('/wallet'));
        audit_log('funds.withdraw_paid', 'withdraw_request', (string) $withdrawId);
    }

    public function rejectWithdraw(int $withdrawId, int $adminId, string $notes = ''): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $row = $db->fetch('SELECT * FROM withdraw_requests WHERE id = ? FOR UPDATE', [$withdrawId]);
            if (!$row || $row['status'] !== 'pending') {
                throw new RuntimeException('Retiro no pendiente.');
            }
            $amount = (float) $row['amount'];
            $this->wallet->deposit((int) $row['user_id'], $amount, 'Reintegro retiro rechazado #' . $withdrawId);
            $this->bumpAum($amount);
            $db->update('withdraw_requests', [
                'status' => 'rejected',
                'admin_id' => $adminId,
                'admin_notes' => $notes !== '' ? $notes : 'Rechazado',
                'paid_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$withdrawId]);
            $this->closePayout($withdrawId, 'failed');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        notify((int) $row['user_id'], 'Retiro rechazado', $notes !== '' ? $notes : 'Tu retiro fue rechazado y el saldo se reintegró.', url('/wallet'));
        audit_log('funds.withdraw_reject', 'withdraw_request', (string) $withdrawId);
    }

    public function saveMandate(int $userId, array $data): void
    {
        $mode = ($data['mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
        $payload = [
            'user_id' => $userId,
            'mode' => $mode,
            'max_per_loan' => max(100, (float) ($data['max_per_loan'] ?? 50000)),
            'max_total_exposure' => max(100, (float) ($data['max_total_exposure'] ?? 500000)),
            'min_annual_rate' => max(0, (float) ($data['min_annual_rate'] ?? 0)),
            'allowed_bands' => substr(preg_replace('/[^A-Fa-fPpa-c,]/', '', (string) ($data['allowed_bands'] ?? 'AA,A,B,C')) ?: 'AA,A,B,C', 0, 40),
            'use_platform_decision' => $mode === 'auto' ? 1 : 0,
            'active' => isset($data['active']) ? (int) (bool) $data['active'] : 1,
        ];
        $exists = App::db()->fetch('SELECT user_id FROM lender_mandates WHERE user_id = ?', [$userId]);
        if ($exists) {
            unset($payload['user_id']);
            App::db()->update('lender_mandates', $payload, 'user_id = ?', [$userId]);
        } else {
            App::db()->insert('lender_mandates', $payload);
        }
        audit_log('lender.mandate', 'user', (string) $userId, ['mode' => $mode]);
    }

    public function getMandate(int $userId): array
    {
        $m = App::db()->fetch('SELECT * FROM lender_mandates WHERE user_id = ?', [$userId]);
        if ($m) {
            return $m;
        }
        return [
            'user_id' => $userId,
            'mode' => 'manual',
            'max_per_loan' => 50000,
            'max_total_exposure' => 500000,
            'min_annual_rate' => 0,
            'allowed_bands' => 'AA,A,B,C',
            'use_platform_decision' => 0,
            'active' => 1,
        ];
    }

    /**
     * Ambos acumuladores se leen con bloqueo de fila: un read-modify-write sin lock
     * pierde deltas cuando dos depósitos o retiros se procesan a la vez.
     */
    private function bumpAum(float $delta): void
    {
        $db = App::db();
        $this->ensureTreasuryRow();
        $row = $db->fetch('SELECT third_party_aum FROM platform_treasury WHERE id = 1 FOR UPDATE');
        $new = max(0, round((float) ($row['third_party_aum'] ?? 0) + $delta, 2));
        $db->update('platform_treasury', ['third_party_aum' => $new], 'id = ?', [1]);
    }

    private function addOwn(float $delta): void
    {
        $db = App::db();
        $this->ensureTreasuryRow();
        $row = $db->fetch('SELECT own_balance FROM platform_treasury WHERE id = 1 FOR UPDATE');
        $new = max(0, round((float) ($row['own_balance'] ?? 0) + $delta, 2));
        $db->update('platform_treasury', ['own_balance' => $new], 'id = ?', [1]);
    }

    /**
     * Cierra la orden de cash-out asociada. Tolerante a instalaciones sin la
     * migración de Mercado Pago aplicada.
     */
    private function closePayout(int $withdrawId, string $status): void
    {
        try {
            App::db()->query(
                "UPDATE mp_payouts SET status = ? WHERE withdraw_id = ? AND status IN ('queued','sent')",
                [$status, $withdrawId]
            );
        } catch (\Throwable $e) {
            error_log('closePayout omitido: ' . $e->getMessage());
        }
    }

    private function ensureTreasuryRow(): void
    {
        App::db()->query(
            'INSERT IGNORE INTO platform_treasury (id, own_balance, third_party_aum, pending_deposits) VALUES (1, 0, 0, 0)'
        );
    }

    private function logAdmin(int $adminId, int $target, string $dir, float $amount, string $type, string $reason): void
    {
        App::db()->insert('admin_ledger', [
            'admin_id' => $adminId,
            'target_user_id' => $target,
            'direction' => $dir,
            'amount' => $amount,
            'fund_type' => $type,
            'reason' => substr($reason, 0, 255),
            'reference' => generate_reference('ADM'),
        ]);
    }
}
