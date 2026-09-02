<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

final class WalletService
{
    public function ensureWallet(int $userId): array
    {
        $db = App::db();
        $wallet = $db->fetch('SELECT * FROM wallets WHERE user_id = ?', [$userId]);
        if (!$wallet) {
            $id = $db->insert('wallets', [
                'user_id' => $userId,
                'balance' => 0,
                'available_balance' => 0,
                'reserved_balance' => 0,
                'currency' => App::config('currency', 'ARS'),
                'bank_id' => '900',
                'account_type' => 'CA',
                'qr_token' => generate_qr_token(),
                'status' => 'active',
            ]);
            $wallet = $db->fetch('SELECT * FROM wallets WHERE id = ?', [$id]);
        }
        $user = $db->fetch('SELECT dni FROM users WHERE id = ?', [$userId]);
        return (new CvuService())->ensureBankIdentity($userId, $wallet, $user['dni'] ?? null);
    }

    public function deposit(int $userId, float $amount, string $description = 'Carga de saldo'): array
    {
        $min = (float) App::config('wallet.min_deposit', 100);
        $max = (float) App::config('wallet.max_deposit', 2000000);
        if ($amount < $min || $amount > $max) {
            throw new RuntimeException('Monto de depósito fuera de límites.');
        }

        $db = App::db();
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
        try {
            $wallet = $this->lockWallet($userId);
            $this->assertCanReceive($wallet);

            $newBalance = round((float) $wallet['balance'] + $amount, 2);
            $newAvailable = round((float) $wallet['available_balance'] + $amount, 2);
            $ref = generate_reference('DEP');

            $db->update('wallets', [
                'balance' => $newBalance,
                'available_balance' => $newAvailable,
            ], 'id = ?', [(int) $wallet['id']]);

            $txId = $db->insert('wallet_transactions', [
                'wallet_id' => (int) $wallet['id'],
                'user_id' => $userId,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $ref,
                'description' => $description,
            ]);

            if ($ownsTx) {
                $db->commit();
            }
            audit_log('wallet.deposit', 'wallet_transaction', (string) $txId, ['amount' => $amount]);
            return ['reference' => $ref, 'balance' => $newBalance];
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function transfer(int $fromUserId, int $toUserId, float $amount, string $note = '', string $idempotencyKey = ''): array
    {
        $min = (float) App::config('wallet.min_transfer', 50);
        $max = (float) App::config('wallet.max_transfer', App::config('wallet.max_withdraw', 5000000));
        if ($amount < $min) {
            throw new RuntimeException('Monto mínimo de transferencia: ' . money($min));
        }
        if ($amount > $max) {
            throw new RuntimeException('Monto máximo de transferencia: ' . money($max));
        }
        if ($fromUserId === $toUserId) {
            throw new RuntimeException('No podés transferirte a vos mismo.');
        }

        $db = App::db();

        // Idempotencia: un doble envío del formulario no puede mover el dinero dos veces.
        if ($idempotencyKey !== '') {
            $prev = $db->fetch(
                'SELECT reference FROM wallet_transactions WHERE idempotency_key = ?',
                [$idempotencyKey]
            );
            if ($prev) {
                return ['out_reference' => (string) $prev['reference'], 'in_reference' => '', 'duplicate' => true];
            }
        }

        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
        try {
            // Lock en orden estable para evitar deadlocks
            $ids = [$fromUserId, $toUserId];
            sort($ids);
            $w1 = $this->lockWallet($ids[0]);
            $w2 = $this->lockWallet($ids[1]);
            $from = $fromUserId === (int) $w1['user_id'] ? $w1 : $w2;
            $to = $toUserId === (int) $w1['user_id'] ? $w1 : $w2;

            $this->assertActive($from);
            $this->assertActive($to);

            if ((float) $from['available_balance'] < $amount) {
                throw new RuntimeException('Saldo disponible insuficiente.');
            }

            $fromBal = round((float) $from['balance'] - $amount, 2);
            $fromAvail = round((float) $from['available_balance'] - $amount, 2);
            $toBal = round((float) $to['balance'] + $amount, 2);
            $toAvail = round((float) $to['available_balance'] + $amount, 2);

            $outRef = generate_reference('OUT');
            $inRef = generate_reference('IN');

            $db->update('wallets', [
                'balance' => $fromBal,
                'available_balance' => $fromAvail,
            ], 'id = ?', [(int) $from['id']]);

            $db->update('wallets', [
                'balance' => $toBal,
                'available_balance' => $toAvail,
            ], 'id = ?', [(int) $to['id']]);

            $db->insert('wallet_transactions', [
                'wallet_id' => (int) $from['id'],
                'user_id' => $fromUserId,
                'type' => 'transfer_out',
                'amount' => $amount,
                'balance_after' => $fromBal,
                'reference' => $outRef,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'related_user_id' => $toUserId,
                'description' => $note !== '' ? $note : 'Transferencia enviada',
            ]);

            $db->insert('wallet_transactions', [
                'wallet_id' => (int) $to['id'],
                'user_id' => $toUserId,
                'type' => 'transfer_in',
                'amount' => $amount,
                'balance_after' => $toBal,
                'reference' => $inRef,
                'related_user_id' => $fromUserId,
                'description' => $note !== '' ? $note : 'Transferencia recibida',
            ]);

            if ($ownsTx) {
                $db->commit();
            }
            notify($toUserId, 'Transferencia recibida', 'Recibiste ' . money($amount) . ' en tu billetera Credimax.', url('/wallet'));
            audit_log('wallet.transfer', 'user', (string) $toUserId, ['amount' => $amount]);
            return ['out_reference' => $outRef, 'in_reference' => $inRef];
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function payByQr(int $fromUserId, string $qrToken, float $amount, string $note = '', string $idempotencyKey = ''): array
    {
        $db = App::db();
        $toWallet = $db->fetch('SELECT * FROM wallets WHERE qr_token = ?', [$qrToken]);
        if (!$toWallet) {
            throw new RuntimeException('QR inválido o expirado.');
        }
        $toUserId = (int) $toWallet['user_id'];
        $ref = generate_reference('QR');

        // El registro del pago QR va dentro de la misma transacción que el movimiento
        // de fondos: o quedan ambos, o no queda ninguno.
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
        try {
            $result = $this->transfer($fromUserId, $toUserId, $amount, $note !== '' ? $note : 'Pago QR Credimax', $idempotencyKey);
            $db->insert('qr_payments', [
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'amount' => $amount,
                'qr_token' => $qrToken,
                'reference' => $ref,
                'status' => 'completed',
                'note' => $note !== '' ? $note : null,
            ]);
            if ($ownsTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
            throw $e;
        }

        return array_merge($result, ['qr_reference' => $ref, 'to_user_id' => $toUserId]);
    }

    public function reserve(int $userId, float $amount): void
    {
        $db = App::db();
        $wallet = $this->lockWallet($userId);
        $this->assertActive($wallet);
        if ((float) $wallet['available_balance'] < $amount) {
            throw new RuntimeException('Saldo insuficiente para reservar fondos.');
        }
        $db->update('wallets', [
            'available_balance' => round((float) $wallet['available_balance'] - $amount, 2),
            'reserved_balance' => round((float) $wallet['reserved_balance'] + $amount, 2),
        ], 'id = ?', [(int) $wallet['id']]);
    }

    public function releaseReserve(int $userId, float $amount): void
    {
        $db = App::db();
        $wallet = $this->lockWallet($userId);
        $reserved = (float) $wallet['reserved_balance'];
        $use = min($reserved, $amount);
        $db->update('wallets', [
            'available_balance' => round((float) $wallet['available_balance'] + $use, 2),
            'reserved_balance' => round($reserved - $use, 2),
        ], 'id = ?', [(int) $wallet['id']]);
    }

    public function consumeReserve(int $userId, float $amount, string $type, string $description, ?int $relatedLoanId = null, ?int $relatedUserId = null): string
    {
        $db = App::db();
        $wallet = $this->lockWallet($userId);
        if ((float) $wallet['reserved_balance'] < $amount) {
            throw new RuntimeException('Reserva insuficiente.');
        }
        $newBal = round((float) $wallet['balance'] - $amount, 2);
        $newRes = round((float) $wallet['reserved_balance'] - $amount, 2);
        $ref = generate_reference('RSV');
        $db->update('wallets', [
            'balance' => $newBal,
            'reserved_balance' => $newRes,
        ], 'id = ?', [(int) $wallet['id']]);
        $db->insert('wallet_transactions', [
            'wallet_id' => (int) $wallet['id'],
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $newBal,
            'reference' => $ref,
            'related_loan_id' => $relatedLoanId,
            'related_user_id' => $relatedUserId,
            'description' => $description,
        ]);
        return $ref;
    }

    public function credit(int $userId, float $amount, string $type, string $description, ?int $relatedLoanId = null, ?int $relatedUserId = null): string
    {
        $db = App::db();
        $wallet = $this->lockWallet($userId);
        $this->assertCanReceive($wallet);
        $newBal = round((float) $wallet['balance'] + $amount, 2);
        $newAvail = round((float) $wallet['available_balance'] + $amount, 2);
        $ref = generate_reference('CR');
        $db->update('wallets', [
            'balance' => $newBal,
            'available_balance' => $newAvail,
        ], 'id = ?', [(int) $wallet['id']]);
        $db->insert('wallet_transactions', [
            'wallet_id' => (int) $wallet['id'],
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $newBal,
            'reference' => $ref,
            'related_loan_id' => $relatedLoanId,
            'related_user_id' => $relatedUserId,
            'description' => $description,
        ]);
        return $ref;
    }

    public function debit(int $userId, float $amount, string $type, string $description, ?int $relatedLoanId = null): string
    {
        $db = App::db();
        $wallet = $this->lockWallet($userId);
        $this->assertActive($wallet);
        if ((float) $wallet['available_balance'] < $amount) {
            throw new RuntimeException('Saldo insuficiente.');
        }
        $newBal = round((float) $wallet['balance'] - $amount, 2);
        $newAvail = round((float) $wallet['available_balance'] - $amount, 2);
        $ref = generate_reference('DB');
        $db->update('wallets', [
            'balance' => $newBal,
            'available_balance' => $newAvail,
        ], 'id = ?', [(int) $wallet['id']]);
        $db->insert('wallet_transactions', [
            'wallet_id' => (int) $wallet['id'],
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $newBal,
            'reference' => $ref,
            'related_loan_id' => $relatedLoanId,
            'description' => $description,
        ]);
        return $ref;
    }

    private function lockWallet(int $userId): array
    {
        $sql = 'SELECT w.*, u.status AS user_status FROM wallets w
                JOIN users u ON u.id = w.user_id WHERE w.user_id = ? FOR UPDATE';
        $wallet = App::db()->fetch($sql, [$userId]);
        if (!$wallet) {
            $this->ensureWallet($userId);
            $wallet = App::db()->fetch($sql, [$userId]);
        }
        if (!$wallet) {
            throw new RuntimeException('Billetera no encontrada.');
        }
        return $wallet;
    }

    /** Requisito para que salga dinero: billetera activa y usuario habilitado. */
    private function assertActive(array $wallet): void
    {
        if (($wallet['status'] ?? '') !== 'active') {
            throw new RuntimeException('La billetera no está activa.');
        }
        if (($wallet['user_status'] ?? 'active') !== 'active') {
            throw new RuntimeException('La cuenta está suspendida. Contactá a soporte.');
        }
    }

    /**
     * Requisito para que entre dinero. Más laxo a propósito: si un pago externo
     * ya se cobró, bloquear la acreditación dejaría el dinero en el limbo.
     */
    private function assertCanReceive(array $wallet): void
    {
        if (($wallet['status'] ?? '') === 'closed') {
            throw new RuntimeException('La billetera está cerrada.');
        }
    }
}
