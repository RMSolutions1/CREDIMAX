<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use RuntimeException;

/**
 * Billetera Credimax como sub-cuenta de Mercado Pago.
 *
 * Modelo: una única cuenta madre (collector) de Mercado Pago concentra el dinero real,
 * y cada wallet de usuario es una sub-cuenta virtual del ledger que se mantiene
 * espejada contra los pagos reales de esa cuenta madre.
 *
 *   Cash-in    → preferencia de Checkout Pro por sub-cuenta (external_reference propio)
 *                → webhook payment approved → acreditación idempotente en el ledger
 *   Cobros     → link/QR de cobro por sub-cuenta, con comisión de plataforma opcional
 *   Cash-out   → orden de pago (mp_payouts) contra la cuenta madre, o devolución real por API
 *   Control    → conciliación cuenta madre ↔ ledger interno
 */
final class MpSubAccountService
{
    public const REF_TOPUP = 'CMX-TOPUP-';
    public const REF_CHARGE = 'CMX-CHARGE-';

    private MercadoPagoService $mp;
    private WalletService $wallet;

    public function __construct(?MercadoPagoService $mp = null, ?WalletService $wallet = null)
    {
        $this->mp = $mp ?? new MercadoPagoService();
        $this->wallet = $wallet ?? new WalletService();
    }

    public function mp(): MercadoPagoService
    {
        return $this->mp;
    }

    // ------------------------------------------------------------ Sub-cuenta

    /** Crea (si hace falta) y devuelve la sub-cuenta Mercado Pago del usuario. */
    public function ensureSubAccount(int $userId): array
    {
        $db = App::db();
        $row = $db->fetch('SELECT * FROM mp_subaccounts WHERE user_id = ?', [$userId]);
        if ($row) {
            return $row;
        }

        $wallet = $this->wallet->ensureWallet($userId);
        $db->insert('mp_subaccounts', [
            'user_id' => $userId,
            'wallet_id' => (int) $wallet['id'],
            'external_id' => 'CMX-SUB-' . str_pad((string) $userId, 8, '0', STR_PAD_LEFT),
            'status' => 'unlinked',
        ]);

        return $db->fetch('SELECT * FROM mp_subaccounts WHERE user_id = ?', [$userId]) ?? [];
    }

    /** Resumen para la UI: saldo del ledger + acumulados reales de Mercado Pago. */
    public function summary(int $userId): array
    {
        $sub = $this->ensureSubAccount($userId);
        $wallet = $this->wallet->ensureWallet($userId);
        $db = App::db();

        $pending = (float) ($db->fetch(
            "SELECT COALESCE(SUM(amount),0) s FROM mp_payments
             WHERE user_id = ? AND status IN ('pending','in_process','authorized') AND credited = 0",
            [$userId]
        )['s'] ?? 0);

        return [
            'subaccount' => $sub,
            'wallet' => $wallet,
            'collected_total' => (float) ($sub['collected_total'] ?? 0),
            'paid_out_total' => (float) ($sub['paid_out_total'] ?? 0),
            'pending_amount' => $pending,
            'linked' => ($sub['status'] ?? '') === 'linked',
        ];
    }

    // -------------------------------------------------------------- Cash-in

    /**
     * Inicia una carga de saldo: registra el depósito pendiente y crea la preferencia
     * de Checkout Pro asociada a la sub-cuenta del usuario.
     *
     * @return array{deposit_id:int,payment_id:int,init_point:string,preference_id:string,amount:float}
     */
    public function createTopup(int $userId, float $amount, string $description = 'Carga de saldo Credimax'): array
    {
        $this->assertEnabled();

        $min = (float) App::config('wallet.min_deposit', 100);
        $max = (float) App::config('wallet.max_deposit', 5000000);
        $amount = round($amount, 2);
        if ($amount < $min || $amount > $max) {
            throw new RuntimeException('El monto debe estar entre ' . money($min) . ' y ' . money($max) . '.');
        }

        $this->ensureSubAccount($userId);
        $db = App::db();
        $user = $db->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        $depositId = $db->insert('fund_deposits', [
            'user_id' => $userId,
            'amount' => $amount,
            'method' => 'transfer',
            'status' => 'pending',
            'channel' => 'mercadopago',
        ]);

        $externalRef = self::REF_TOPUP . $depositId;
        $db->update('fund_deposits', ['external_reference' => $externalRef], 'id = ?', [$depositId]);

        $minutes = (int) App::config('mercadopago.expiration_minutes', 60);
        $preference = [
            'items' => [[
                'id' => $externalRef,
                'title' => 'Carga de saldo Credimax',
                'description' => substr($description, 0, 250),
                'category_id' => 'services',
                'quantity' => 1,
                'currency_id' => App::config('currency', 'ARS'),
                'unit_price' => $amount,
            ]],
            'payer' => $this->payerPayload($user),
            'payment_methods' => $this->paymentMethodsPayload(),
            'external_reference' => $externalRef,
            'statement_descriptor' => substr((string) App::config('mercadopago.statement_descriptor', 'CREDIMAX'), 0, 22),
            'notification_url' => absolute_url('/webhooks/mercadopago'),
            'back_urls' => [
                'success' => absolute_url('/wallet/mp/retorno'),
                'pending' => absolute_url('/wallet/mp/retorno'),
                'failure' => absolute_url('/wallet/mp/retorno'),
            ],
            'binary_mode' => (bool) App::config('mercadopago.binary_mode', false),
            'expires' => true,
            'expiration_date_from' => date('c'),
            'expiration_date_to' => date('c', time() + $minutes * 60),
            'metadata' => [
                'credimax_user_id' => $userId,
                'credimax_id' => (string) $user['credimax_id'],
                'credimax_deposit_id' => $depositId,
                'credimax_kind' => 'topup',
            ],
        ];
        // auto_return exige HTTPS; en local (http) se omite para no rechazar la preferencia.
        if (str_starts_with((string) App::config('app_url', ''), 'https://')) {
            $preference['auto_return'] = 'approved';
        }

        // En local, notification_url a localhost suele rechazarse: se acredita por retorno + conciliación.
        if (!str_starts_with((string) App::config('app_url', ''), 'https://')) {
            unset($preference['notification_url']);
        }

        $result = $this->mp->createPreference($preference, 'cmx-topup-' . $depositId);
        if (!$result['ok']) {
            $db->update('fund_deposits', [
                'status' => 'cancelled',
                'admin_notes' => substr('Error Mercado Pago: ' . ($result['error'] ?? ''), 0, 255),
            ], 'id = ?', [$depositId]);
            throw new RuntimeException('Mercado Pago rechazó la solicitud: ' . ($result['error'] ?? 'error desconocido'));
        }

        $data = $result['data'];
        $initPoint = (string) ($this->mp->isSandbox()
            ? ($data['sandbox_init_point'] ?? $data['init_point'] ?? '')
            : ($data['init_point'] ?? ''));

        $paymentRowId = $db->insert('mp_payments', [
            'user_id' => $userId,
            'deposit_id' => $depositId,
            'kind' => 'topup',
            'status' => 'created',
            'amount' => $amount,
            'currency' => App::config('currency', 'ARS'),
            'external_reference' => $externalRef,
            'preference_id' => substr((string) ($data['id'] ?? ''), 0, 80),
            'init_point' => substr($initPoint, 0, 500),
        ]);

        audit_log('mp.topup_created', 'fund_deposit', (string) $depositId, ['amount' => $amount]);

        return [
            'deposit_id' => $depositId,
            'payment_id' => $paymentRowId,
            'init_point' => $initPoint,
            'preference_id' => (string) ($data['id'] ?? ''),
            'amount' => $amount,
        ];
    }

    // ------------------------------------------------------- Links de cobro

    /**
     * Genera un link/QR de cobro a favor de la sub-cuenta del usuario.
     * El dinero entra a la cuenta madre y se acredita al cobrador menos la comisión.
     */
    public function createCharge(int $userId, float $amount, string $title, string $note = ''): array
    {
        $this->assertEnabled();

        $amount = round($amount, 2);
        $min = (float) App::config('wallet.min_transfer', 50);
        if ($amount < $min) {
            throw new RuntimeException('El monto mínimo de cobro es ' . money($min) . '.');
        }

        $this->ensureSubAccount($userId);
        $db = App::db();
        $user = $db->fetch('SELECT id, email, credimax_id, first_name, last_name FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        $feePct = (float) App::config('mercadopago.charge_fee_pct', App::config('wallet.platform_fee_pct', 0));
        $chargeId = $db->insert('mp_charges', [
            'user_id' => $userId,
            'code' => strtoupper(bin2hex(random_bytes(6))),
            'title' => substr($title !== '' ? $title : 'Cobro Credimax', 0, 120),
            'note' => $note !== '' ? substr($note, 0, 255) : null,
            'amount' => $amount,
            'fee_pct' => $feePct,
            'status' => 'open',
        ]);

        $externalRef = self::REF_CHARGE . $chargeId;
        $minutes = (int) App::config('mercadopago.charge_expiration_minutes', 1440);

        $preference = [
            'items' => [[
                'id' => $externalRef,
                'title' => substr($title !== '' ? $title : 'Cobro Credimax', 0, 120),
                'description' => substr($note !== '' ? $note : 'Pago a ' . $user['credimax_id'], 0, 250),
                'category_id' => 'services',
                'quantity' => 1,
                'currency_id' => App::config('currency', 'ARS'),
                'unit_price' => $amount,
            ]],
            'payment_methods' => $this->paymentMethodsPayload(),
            'external_reference' => $externalRef,
            'statement_descriptor' => substr((string) App::config('mercadopago.statement_descriptor', 'CREDIMAX'), 0, 22),
            'notification_url' => absolute_url('/webhooks/mercadopago'),
            'back_urls' => [
                'success' => absolute_url('/cobro/' . $externalRef),
                'pending' => absolute_url('/cobro/' . $externalRef),
                'failure' => absolute_url('/cobro/' . $externalRef),
            ],
            'expires' => true,
            'expiration_date_from' => date('c'),
            'expiration_date_to' => date('c', time() + $minutes * 60),
            'metadata' => [
                'credimax_user_id' => $userId,
                'credimax_charge_id' => $chargeId,
                'credimax_kind' => 'charge',
            ],
        ];
        if (str_starts_with((string) App::config('app_url', ''), 'https://')) {
            $preference['auto_return'] = 'approved';
        } else {
            unset($preference['notification_url']);
        }

        $result = $this->mp->createPreference($preference, 'cmx-charge-' . $chargeId);
        if (!$result['ok']) {
            $db->update('mp_charges', ['status' => 'cancelled'], 'id = ?', [$chargeId]);
            throw new RuntimeException('Mercado Pago rechazó la solicitud: ' . ($result['error'] ?? 'error desconocido'));
        }

        $data = $result['data'];
        $initPoint = (string) ($this->mp->isSandbox()
            ? ($data['sandbox_init_point'] ?? $data['init_point'] ?? '')
            : ($data['init_point'] ?? ''));

        $db->update('mp_charges', [
            'preference_id' => substr((string) ($data['id'] ?? ''), 0, 80),
            'init_point' => substr($initPoint, 0, 500),
            'external_reference' => $externalRef,
            'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
        ], 'id = ?', [$chargeId]);

        $db->insert('mp_payments', [
            'user_id' => $userId,
            'charge_id' => $chargeId,
            'kind' => 'charge',
            'status' => 'created',
            'amount' => $amount,
            'currency' => App::config('currency', 'ARS'),
            'external_reference' => $externalRef,
            'preference_id' => substr((string) ($data['id'] ?? ''), 0, 80),
            'init_point' => substr($initPoint, 0, 500),
        ]);

        audit_log('mp.charge_created', 'mp_charge', (string) $chargeId, ['amount' => $amount]);

        return $db->fetch('SELECT * FROM mp_charges WHERE id = ?', [$chargeId]) ?? [];
    }

    // -------------------------------------------------- Acreditación / webhook

    /**
     * Punto de entrada tras una notificación: consulta el pago real en Mercado Pago
     * (nunca se confía en el cuerpo del webhook) y sincroniza el ledger.
     *
     * @return array{handled:bool,reason:string,credited:bool}
     */
    public function syncPayment(string $paymentId): array
    {
        $result = $this->mp->getPayment($paymentId);
        if (!$result['ok']) {
            throw new RuntimeException('No se pudo leer el pago ' . $paymentId . ': ' . ($result['error'] ?? ''));
        }

        $payment = $result['data'];
        $externalRef = (string) ($payment['external_reference'] ?? '');
        $status = (string) ($payment['status'] ?? 'unknown');

        if (str_starts_with($externalRef, self::REF_TOPUP)) {
            return $this->applyTopupPayment($payment);
        }
        if (str_starts_with($externalRef, self::REF_CHARGE)) {
            return $this->applyChargePayment($payment);
        }

        $this->storePayment($payment, ['kind' => 'external']);
        return ['handled' => false, 'reason' => 'external_reference desconocida (' . $externalRef . '), estado ' . $status, 'credited' => false];
    }

    /** Acredita una carga de saldo aprobada en la sub-cuenta, de forma idempotente. */
    private function applyTopupPayment(array $payment): array
    {
        $db = App::db();
        $externalRef = (string) $payment['external_reference'];
        $depositId = (int) substr($externalRef, strlen(self::REF_TOPUP));
        $status = (string) ($payment['status'] ?? '');
        $mpPaymentId = (string) ($payment['id'] ?? '');

        $deposit = $db->fetch('SELECT * FROM fund_deposits WHERE id = ?', [$depositId]);
        if (!$deposit) {
            return ['handled' => false, 'reason' => 'Depósito #' . $depositId . ' inexistente', 'credited' => false];
        }

        $row = $this->storePayment($payment, ['kind' => 'topup', 'deposit_id' => $depositId, 'user_id' => (int) $deposit['user_id']]);

        if ($status !== 'approved') {
            $this->markDepositNotApproved($deposit, $status, $payment);
            return ['handled' => true, 'reason' => 'Pago en estado ' . $status, 'credited' => false];
        }

        // El monto acreditado nunca puede superar lo realmente cobrado por Mercado Pago.
        $paidAmount = round((float) ($payment['transaction_amount'] ?? 0), 2);
        if ($paidAmount + 0.009 < (float) $deposit['amount']) {
            throw new RuntimeException(
                'Monto cobrado (' . $paidAmount . ') menor al depósito #' . $depositId . ' (' . $deposit['amount'] . ').'
            );
        }

        $netReceived = round((float) ($payment['transaction_details']['net_received_amount'] ?? $paidAmount), 2);
        $creditAmount = App::config('mercadopago.topup_fee_mode', 'absorb') === 'transfer' && $netReceived > 0
            ? $netReceived
            : $paidAmount;

        $db->beginTransaction();
        try {
            // Bloqueo del registro del pago: garantiza una sola acreditación aunque
            // lleguen webhooks simultáneos o se reintente la conciliación.
            $locked = $db->fetch('SELECT * FROM mp_payments WHERE id = ? FOR UPDATE', [(int) $row['id']]);
            if (!$locked || (int) $locked['credited'] === 1) {
                $db->commit();
                return ['handled' => true, 'reason' => 'Pago ya acreditado', 'credited' => false];
            }

            $dep = $db->fetch('SELECT * FROM fund_deposits WHERE id = ? FOR UPDATE', [$depositId]);
            if (!$dep || $dep['status'] !== 'pending') {
                $db->update('mp_payments', ['credited' => 1, 'credited_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $row['id']]);
                $db->commit();
                return ['handled' => true, 'reason' => 'Depósito ya procesado', 'credited' => false];
            }

            $userId = (int) $dep['user_id'];
            $credit = $this->wallet->deposit($userId, $creditAmount, 'Carga vía Mercado Pago #' . $mpPaymentId);

            $db->update('fund_deposits', [
                'status' => 'confirmed',
                'admin_notes' => 'Acreditado automáticamente por Mercado Pago (pago ' . $mpPaymentId . ')',
                'confirmed_at' => date('Y-m-d H:i:s'),
                'mp_payment_id' => $mpPaymentId,
            ], 'id = ?', [$depositId]);

            $db->update('mp_payments', [
                'credited' => 1,
                'credited_at' => date('Y-m-d H:i:s'),
                'wallet_tx_reference' => $credit['reference'],
            ], 'id = ?', [(int) $row['id']]);

            $this->bumpTreasuryAum($creditAmount);
            $this->bumpSubAccount($userId, $creditAmount, 0.0);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        notify(
            (int) $deposit['user_id'],
            'Saldo acreditado',
            'Se acreditaron ' . money($creditAmount) . ' en tu billetera Credimax vía Mercado Pago.',
            url('/wallet')
        );
        audit_log('mp.topup_credited', 'fund_deposit', (string) $depositId, [
            'mp_payment_id' => $mpPaymentId,
            'amount' => $creditAmount,
        ]);

        return ['handled' => true, 'reason' => 'Acreditado ' . money($creditAmount), 'credited' => true];
    }

    /** Acredita un cobro (link/QR) al usuario cobrador, descontando la comisión. */
    private function applyChargePayment(array $payment): array
    {
        $db = App::db();
        $externalRef = (string) $payment['external_reference'];
        $chargeId = (int) substr($externalRef, strlen(self::REF_CHARGE));
        $status = (string) ($payment['status'] ?? '');
        $mpPaymentId = (string) ($payment['id'] ?? '');

        $charge = $db->fetch('SELECT * FROM mp_charges WHERE id = ?', [$chargeId]);
        if (!$charge) {
            return ['handled' => false, 'reason' => 'Cobro #' . $chargeId . ' inexistente', 'credited' => false];
        }

        $row = $this->storePayment($payment, ['kind' => 'charge', 'charge_id' => $chargeId, 'user_id' => (int) $charge['user_id']]);

        if ($status !== 'approved') {
            return ['handled' => true, 'reason' => 'Cobro en estado ' . $status, 'credited' => false];
        }

        $paidAmount = round((float) ($payment['transaction_amount'] ?? 0), 2);
        $feePct = (float) ($charge['fee_pct'] ?? 0);
        $fee = round($paidAmount * $feePct / 100, 2);
        $creditAmount = round($paidAmount - $fee, 2);
        if ($creditAmount <= 0) {
            return ['handled' => true, 'reason' => 'Monto neto no positivo', 'credited' => false];
        }

        $db->beginTransaction();
        try {
            $locked = $db->fetch('SELECT * FROM mp_payments WHERE id = ? FOR UPDATE', [(int) $row['id']]);
            if (!$locked || (int) $locked['credited'] === 1) {
                $db->commit();
                return ['handled' => true, 'reason' => 'Cobro ya acreditado', 'credited' => false];
            }

            $ch = $db->fetch('SELECT * FROM mp_charges WHERE id = ? FOR UPDATE', [$chargeId]);
            if (!$ch || $ch['status'] !== 'open') {
                $db->update('mp_payments', ['credited' => 1, 'credited_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $row['id']]);
                $db->commit();
                return ['handled' => true, 'reason' => 'Cobro ya cerrado', 'credited' => false];
            }

            $userId = (int) $ch['user_id'];
            $ref = $this->wallet->credit($userId, $creditAmount, 'deposit', 'Cobro Mercado Pago #' . $mpPaymentId);
            if ($fee > 0) {
                App::db()->insert('admin_ledger', [
                    'admin_id' => 0,
                    'target_user_id' => $userId,
                    'direction' => 'credit',
                    'amount' => $fee,
                    'fund_type' => 'own',
                    'reason' => 'Comisión de cobro Mercado Pago #' . $mpPaymentId,
                    'reference' => generate_reference('FEE'),
                ]);
                $this->addTreasuryOwn($fee);
            }

            $db->update('mp_charges', [
                'status' => 'paid',
                'paid_payment_id' => $mpPaymentId,
                'paid_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$chargeId]);

            $db->update('mp_payments', [
                'credited' => 1,
                'credited_at' => date('Y-m-d H:i:s'),
                'wallet_tx_reference' => $ref,
                'fee_amount' => $fee,
            ], 'id = ?', [(int) $row['id']]);

            $this->bumpTreasuryAum($creditAmount);
            $this->bumpSubAccount($userId, $creditAmount, 0.0);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        notify(
            (int) $charge['user_id'],
            'Cobro recibido',
            'Recibiste ' . money($creditAmount) . ' por tu link de cobro Mercado Pago.',
            url('/wallet')
        );
        audit_log('mp.charge_credited', 'mp_charge', (string) $chargeId, ['amount' => $creditAmount]);

        return ['handled' => true, 'reason' => 'Cobro acreditado ' . money($creditAmount), 'credited' => true];
    }

    private function markDepositNotApproved(array $deposit, string $status, array $payment): void
    {
        if ($deposit['status'] !== 'pending') {
            return;
        }
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            App::db()->update('fund_deposits', [
                'status' => 'cancelled',
                'admin_notes' => substr('Mercado Pago: ' . $status . ' — ' . (string) ($payment['status_detail'] ?? ''), 0, 255),
            ], 'id = ?', [(int) $deposit['id']]);
        }
    }

    /** Guarda o actualiza el espejo local del pago de Mercado Pago. */
    private function storePayment(array $payment, array $extra = []): array
    {
        $db = App::db();
        $mpPaymentId = (string) ($payment['id'] ?? '');
        if ($mpPaymentId === '') {
            throw new RuntimeException('El pago de Mercado Pago no trae id.');
        }

        $data = [
            'status' => substr((string) ($payment['status'] ?? ''), 0, 30),
            'status_detail' => substr((string) ($payment['status_detail'] ?? ''), 0, 60),
            'amount' => round((float) ($payment['transaction_amount'] ?? 0), 2),
            'net_amount' => round((float) ($payment['transaction_details']['net_received_amount'] ?? 0), 2),
            'currency' => substr((string) ($payment['currency_id'] ?? 'ARS'), 0, 3),
            'payment_method_id' => substr((string) ($payment['payment_method_id'] ?? ''), 0, 40),
            'payment_type_id' => substr((string) ($payment['payment_type_id'] ?? ''), 0, 40),
            'payer_email' => substr((string) ($payment['payer']['email'] ?? ''), 0, 190),
            'merchant_order_id' => substr((string) ($payment['order']['id'] ?? ''), 0, 40),
            'external_reference' => substr((string) ($payment['external_reference'] ?? ''), 0, 80),
            'raw' => json_encode($payment, JSON_UNESCAPED_UNICODE) ?: null,
        ];

        $existing = $db->fetch('SELECT * FROM mp_payments WHERE mp_payment_id = ?', [$mpPaymentId]);
        if ($existing) {
            $db->update('mp_payments', $data, 'id = ?', [(int) $existing['id']]);
            return $db->fetch('SELECT * FROM mp_payments WHERE id = ?', [(int) $existing['id']]) ?? $existing;
        }

        // La fila "created" se generó al crear la preferencia: se completa con el pago real.
        $placeholder = $db->fetch(
            "SELECT * FROM mp_payments WHERE external_reference = ? AND mp_payment_id IS NULL AND status = 'created' LIMIT 1",
            [$data['external_reference']]
        );
        if ($placeholder) {
            $data['mp_payment_id'] = $mpPaymentId;
            $db->update('mp_payments', $data, 'id = ?', [(int) $placeholder['id']]);
            return $db->fetch('SELECT * FROM mp_payments WHERE id = ?', [(int) $placeholder['id']]) ?? $placeholder;
        }

        $data['mp_payment_id'] = $mpPaymentId;
        $data['kind'] = substr((string) ($extra['kind'] ?? 'external'), 0, 20);
        $data['user_id'] = $extra['user_id'] ?? null;
        $data['deposit_id'] = $extra['deposit_id'] ?? null;
        $data['charge_id'] = $extra['charge_id'] ?? null;
        $id = $db->insert('mp_payments', $data);

        return $db->fetch('SELECT * FROM mp_payments WHERE id = ?', [$id]) ?? [];
    }

    // -------------------------------------------------------------- Cash-out

    /**
     * Devuelve por API un cash-in acreditado (reverso real del dinero al pagador).
     * Es el único cash-out totalmente automatizable contra Mercado Pago.
     */
    public function refundTopup(int $mpPaymentRowId, ?float $amount, int $adminId): array
    {
        $db = App::db();
        $row = $db->fetch('SELECT * FROM mp_payments WHERE id = ?', [$mpPaymentRowId]);
        if (!$row || $row['status'] !== 'approved') {
            throw new RuntimeException('El pago no está aprobado.');
        }
        if ((int) $row['credited'] !== 1) {
            throw new RuntimeException('El pago no fue acreditado en el ledger.');
        }
        $userId = (int) $row['user_id'];
        $refundAmount = round($amount ?? (float) $row['amount'], 2);
        if ($refundAmount <= 0 || $refundAmount > (float) $row['amount'] + 0.009) {
            throw new RuntimeException('Monto de devolución inválido.');
        }

        // El saldo se retira del ledger antes de pedir la devolución para no
        // dejar al usuario con dinero que ya no respalda la cuenta madre.
        $db->beginTransaction();
        try {
            $this->wallet->debit($userId, $refundAmount, 'refund', 'Devolución Mercado Pago #' . $row['mp_payment_id']);
            $this->bumpTreasuryAum(-$refundAmount);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $result = $this->mp->refundPayment(
            (string) $row['mp_payment_id'],
            $refundAmount,
            'cmx-refund-' . $mpPaymentRowId . '-' . (int) round($refundAmount * 100)
        );

        if (!$result['ok']) {
            // Reversa compensatoria: el dinero nunca salió de Mercado Pago.
            $this->wallet->credit($userId, $refundAmount, 'adjustment', 'Reversa de devolución fallida Mercado Pago');
            $this->bumpTreasuryAum($refundAmount);
            throw new RuntimeException('Mercado Pago rechazó la devolución: ' . ($result['error'] ?? ''));
        }

        $db->update('mp_payments', [
            'refunded_amount' => round((float) $row['refunded_amount'] + $refundAmount, 2),
        ], 'id = ?', [$mpPaymentRowId]);

        notify($userId, 'Devolución procesada', 'Se devolvieron ' . money($refundAmount) . ' a tu medio de pago original.', url('/wallet'));
        audit_log('mp.refund', 'mp_payment', (string) $mpPaymentRowId, ['amount' => $refundAmount, 'admin' => $adminId]);

        return $result['data'];
    }

    /**
     * Encola la orden de pago de un retiro contra la cuenta madre.
     * Mercado Pago no expone transferencias a terceros por API, así que la orden
     * queda registrada, validada y trazable para su ejecución y conciliación.
     */
    public function queuePayout(int $withdrawId): int
    {
        $db = App::db();
        $wd = $db->fetch('SELECT * FROM withdraw_requests WHERE id = ?', [$withdrawId]);
        if (!$wd) {
            throw new RuntimeException('Retiro inexistente.');
        }
        $existing = $db->fetch('SELECT id FROM mp_payouts WHERE withdraw_id = ?', [$withdrawId]);
        if ($existing) {
            return (int) $existing['id'];
        }

        $destination = (string) ($wd['destination_cbu'] ?: $wd['destination_alias']);
        return $db->insert('mp_payouts', [
            'withdraw_id' => $withdrawId,
            'user_id' => (int) $wd['user_id'],
            'amount' => (float) $wd['amount'],
            'destination_type' => $wd['destination_cbu'] ? 'cvu' : 'alias',
            'destination' => substr($destination, 0, 60),
            'holder' => $wd['destination_holder'] ? substr((string) $wd['destination_holder'], 0, 160) : null,
            'status' => 'queued',
        ]);
    }

    public function markPayoutSent(int $payoutId, int $adminId, string $operationId, string $notes = ''): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $payout = $db->fetch('SELECT * FROM mp_payouts WHERE id = ? FOR UPDATE', [$payoutId]);
            if (!$payout || $payout['status'] !== 'queued') {
                throw new RuntimeException('La orden de pago no está pendiente.');
            }
            $db->update('mp_payouts', [
                'status' => 'sent',
                'mp_operation_id' => substr($operationId, 0, 60) ?: null,
                'notes' => $notes !== '' ? substr($notes, 0, 255) : null,
                'sent_at' => date('Y-m-d H:i:s'),
                'admin_id' => $adminId,
            ], 'id = ?', [$payoutId]);
            $this->bumpSubAccount((int) $payout['user_id'], 0.0, (float) $payout['amount']);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        audit_log('mp.payout_sent', 'mp_payout', (string) $payoutId, ['operation' => $operationId]);
    }

    // ---------------------------------------------------------- Conciliación

    /**
     * Compara los pagos reales de la cuenta madre contra el ledger interno.
     * Detecta pagos aprobados sin acreditar y acreditaciones sin respaldo.
     */
    public function reconcile(string $from, string $to): array
    {
        $this->assertEnabled();

        // Paginado: con una sola página de 100 los días con mucho volumen dejaban
        // pagos sin revisar y la conciliación daba un falso "todo en orden".
        $remote = [];
        $offset = 0;
        $pageSize = 100;
        do {
            $result = $this->mp->searchPayments([
                'sort' => 'date_created',
                'criteria' => 'desc',
                'range' => 'date_created',
                'begin_date' => $from,
                'end_date' => $to,
                'limit' => $pageSize,
                'offset' => $offset,
            ]);
            if (!$result['ok']) {
                throw new RuntimeException('No se pudo consultar Mercado Pago: ' . ($result['error'] ?? ''));
            }
            $page = $result['data']['results'] ?? [];
            $remote = array_merge($remote, $page);
            $total = (int) ($result['data']['paging']['total'] ?? count($remote));
            $offset += $pageSize;
        } while (count($page) === $pageSize && $offset < $total && $offset < 2000);

        $missing = [];
        $repaired = 0;
        $approvedTotal = 0.0;

        foreach ($remote as $payment) {
            if (($payment['status'] ?? '') !== 'approved') {
                continue;
            }
            $approvedTotal += (float) ($payment['transaction_amount'] ?? 0);
            $mpId = (string) ($payment['id'] ?? '');
            $local = App::db()->fetch('SELECT id, credited FROM mp_payments WHERE mp_payment_id = ?', [$mpId]);
            if ($local && (int) $local['credited'] === 1) {
                continue;
            }
            $missing[] = $mpId;
            try {
                $sync = $this->syncPayment($mpId);
                if ($sync['credited']) {
                    $repaired++;
                }
            } catch (\Throwable $e) {
                error_log('Reconciliación MP ' . $mpId . ': ' . $e->getMessage());
            }
        }

        $ledger = (float) (App::db()->fetch(
            "SELECT COALESCE(SUM(balance),0) s FROM wallets w
             JOIN users u ON u.id = w.user_id WHERE u.role = 'user'"
        )['s'] ?? 0);

        return [
            'checked' => count($remote),
            'approved_total' => round($approvedTotal, 2),
            'uncredited' => $missing,
            'repaired' => $repaired,
            'ledger_customer_balance' => round($ledger, 2),
            'from' => $from,
            'to' => $to,
        ];
    }

    // -------------------------------------------------- Vinculación de cuenta

    /** Paso 1 del OAuth: genera state + PKCE y devuelve la URL de autorización. */
    public function startAccountLink(int $userId): string
    {
        $this->assertEnabled();
        if ($this->mp->clientId() === '' || $this->mp->clientSecret() === '') {
            throw new RuntimeException('Faltan client_id / client_secret de Mercado Pago.');
        }

        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        App::db()->insert('mp_oauth_states', [
            'state' => $state,
            'user_id' => $userId,
            'code_verifier' => Crypto::encrypt($verifier),
            'expires_at' => date('Y-m-d H:i:s', time() + 900),
        ]);

        return $this->mp->oauthAuthorizeUrl($state, $challenge, $this->redirectUri());
    }

    /** Paso 2 del OAuth: canjea el código y guarda los tokens cifrados. */
    public function finishAccountLink(string $code, string $state): array
    {
        $db = App::db();
        $row = $db->fetch('SELECT * FROM mp_oauth_states WHERE state = ?', [$state]);
        if (!$row || (int) $row['used'] === 1) {
            throw new RuntimeException('Solicitud de vinculación inválida o ya utilizada.');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            throw new RuntimeException('La solicitud de vinculación expiró. Probá de nuevo.');
        }
        $db->update('mp_oauth_states', ['used' => 1], 'id = ?', [(int) $row['id']]);

        $verifier = Crypto::decrypt((string) $row['code_verifier']);
        $result = $this->mp->oauthExchange($code, $verifier, $this->redirectUri());
        if (!$result['ok']) {
            throw new RuntimeException('Mercado Pago rechazó la vinculación: ' . ($result['error'] ?? ''));
        }

        $data = $result['data'];
        $userId = (int) $row['user_id'];
        $this->ensureSubAccount($userId);

        $db->update('mp_subaccounts', [
            'mp_user_id' => substr((string) ($data['user_id'] ?? ''), 0, 40),
            'access_token' => Crypto::encrypt((string) ($data['access_token'] ?? '')),
            'refresh_token' => Crypto::encrypt((string) ($data['refresh_token'] ?? '')),
            'public_key' => substr((string) ($data['public_key'] ?? ''), 0, 120),
            'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($data['expires_in'] ?? 15552000)),
            'status' => 'linked',
            'linked_at' => date('Y-m-d H:i:s'),
        ], 'user_id = ?', [$userId]);

        audit_log('mp.account_linked', 'user', (string) $userId, ['mp_user_id' => $data['user_id'] ?? null]);
        notify($userId, 'Cuenta Mercado Pago vinculada', 'Ya podés cobrar y retirar más rápido con tu cuenta vinculada.', url('/wallet/mp'));

        return $db->fetch('SELECT * FROM mp_subaccounts WHERE user_id = ?', [$userId]) ?? [];
    }

    public function unlinkAccount(int $userId): void
    {
        App::db()->update('mp_subaccounts', [
            'status' => 'unlinked',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ], 'user_id = ?', [$userId]);
        audit_log('mp.account_unlinked', 'user', (string) $userId);
    }

    public function redirectUri(): string
    {
        return (string) App::config('mercadopago.redirect_uri', absolute_url('/wallet/mp/vincular/callback'));
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Datos del pagador. Mercado Pago usa identificación, teléfono y domicilio en su
     * motor antifraude: cuanto más completo va el payer, menos rechazos por prevención.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function payerPayload(array $user): array
    {
        $payer = [
            'name' => (string) ($user['first_name'] ?? ''),
            'surname' => (string) ($user['last_name'] ?? ''),
            'last_name' => (string) ($user['last_name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ];

        $doc = preg_replace('/\D+/', '', (string) ($user['dni'] ?? '')) ?? '';
        if ($doc !== '') {
            $payer['identification'] = ['type' => 'DNI', 'number' => $doc];
        }

        $phone = preg_replace('/\D+/', '', (string) ($user['phone'] ?? '')) ?? '';
        if (strlen($phone) >= 8) {
            // Argentina: los primeros dígitos son característica, el resto número.
            $payer['phone'] = [
                'area_code' => substr($phone, 0, strlen($phone) > 10 ? 3 : 2),
                'number' => substr($phone, strlen($phone) > 10 ? 3 : 2),
            ];
        }

        $street = trim((string) ($user['address_street'] ?? ''));
        if ($street !== '') {
            // "Av. Siempre Viva 742" → nombre y altura separados, como espera la API.
            if (preg_match('/^(.*?)[\s,]+(\d+[A-Za-z]?)$/u', $street, $m) === 1) {
                $payer['address'] = ['street_name' => trim($m[1]), 'street_number' => $m[2]];
            } else {
                $payer['address'] = ['street_name' => $street];
            }
            $zip = trim((string) ($user['address_zip'] ?? ''));
            if ($zip !== '') {
                $payer['address']['zip_code'] = $zip;
            }
        }

        return $payer;
    }

    /**
     * Medios de pago y cuotas. Configurables para poder desactivar efectivo cuando
     * se necesita acreditación inmediata del saldo.
     *
     * @return array<string,mixed>
     */
    private function paymentMethodsPayload(): array
    {
        $payload = [
            'installments' => max(1, (int) App::config('mercadopago.max_installments', 12)),
        ];

        $excludedTypes = (array) App::config('mercadopago.excluded_payment_types', []);
        if ($excludedTypes !== []) {
            $payload['excluded_payment_types'] = array_map(
                static fn($id) => ['id' => (string) $id],
                $excludedTypes
            );
        }

        $excludedMethods = (array) App::config('mercadopago.excluded_payment_methods', []);
        if ($excludedMethods !== []) {
            $payload['excluded_payment_methods'] = array_map(
                static fn($id) => ['id' => (string) $id],
                $excludedMethods
            );
        }

        return $payload;
    }

    private function assertEnabled(): void
    {
        if (!$this->mp->isEnabled()) {
            throw new RuntimeException('Mercado Pago no está habilitado. Configuralo en /admin/mercadopago.');
        }
    }

    /** Actualiza el AUM de terceros con lectura bloqueada (evita perder deltas). */
    private function bumpTreasuryAum(float $delta): void
    {
        $db = App::db();
        $db->query('INSERT IGNORE INTO platform_treasury (id, own_balance, third_party_aum, pending_deposits) VALUES (1,0,0,0)');
        $row = $db->fetch('SELECT third_party_aum FROM platform_treasury WHERE id = 1 FOR UPDATE');
        $new = max(0, round((float) ($row['third_party_aum'] ?? 0) + $delta, 2));
        $db->update('platform_treasury', ['third_party_aum' => $new], 'id = ?', [1]);
    }

    private function addTreasuryOwn(float $delta): void
    {
        $db = App::db();
        $db->query('INSERT IGNORE INTO platform_treasury (id, own_balance, third_party_aum, pending_deposits) VALUES (1,0,0,0)');
        $row = $db->fetch('SELECT own_balance FROM platform_treasury WHERE id = 1 FOR UPDATE');
        $new = max(0, round((float) ($row['own_balance'] ?? 0) + $delta, 2));
        $db->update('platform_treasury', ['own_balance' => $new], 'id = ?', [1]);
    }

    private function bumpSubAccount(int $userId, float $collected, float $paidOut): void
    {
        App::db()->query(
            'UPDATE mp_subaccounts SET collected_total = collected_total + ?, paid_out_total = paid_out_total + ? WHERE user_id = ?',
            [round($collected, 2), round($paidOut, 2), $userId]
        );
    }
}
