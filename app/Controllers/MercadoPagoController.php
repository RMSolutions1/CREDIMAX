<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\MpSubAccountService;
use RuntimeException;

/**
 * Sub-cuenta Mercado Pago del usuario: cargar saldo, cobrar por link/QR
 * y vincular su propia cuenta de Mercado Pago.
 */
final class MercadoPagoController
{
    public function index(): void
    {
        require_auth();
        $service = new MpSubAccountService();
        $uid = auth_id();

        $summary = $service->summary($uid);
        $charges = App::db()->fetchAll(
            'SELECT * FROM mp_charges WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [$uid]
        );
        $payments = App::db()->fetchAll(
            'SELECT * FROM mp_payments WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [$uid]
        );

        View::render('wallet/mercadopago', [
            'title' => 'Mi cuenta Mercado Pago',
            'summary' => $summary,
            'charges' => $charges,
            'payments' => $payments,
            'enabled' => $service->mp()->isEnabled(),
            'sandbox' => $service->mp()->isSandbox(),
        ]);
    }

    public function topup(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $amount = parse_amount((string) ($_POST['amount'] ?? '0'));
            $intent = (new MpSubAccountService())->createTopup(auth_id(), $amount);
            redirect($intent['init_point']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/wallet/mp'));
        }
    }

    /**
     * Retorno del checkout. Es solo informativo: la acreditación siempre ocurre
     * por webhook, nunca a partir de parámetros de la URL (que el usuario controla).
     */
    public function callback(): void
    {
        require_auth();
        $ref = trim((string) ($_GET['ref'] ?? $_GET['external_reference'] ?? ''));
        $status = (string) ($_GET['status'] ?? $_GET['collection_status'] ?? '');
        $paymentId = trim((string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? ''));

        // Consulta activa para no obligar al usuario a esperar el webhook.
        if ($paymentId !== '' && ctype_digit($paymentId)) {
            try {
                (new MpSubAccountService())->syncPayment($paymentId);
            } catch (\Throwable $e) {
                error_log('Retorno Mercado Pago: ' . $e->getMessage());
            }
        }

        $deposit = null;
        if ($ref !== '') {
            $deposit = App::db()->fetch(
                'SELECT * FROM fund_deposits WHERE external_reference = ? AND user_id = ?',
                [$ref, auth_id()]
            );
        }
        if (!$deposit && $paymentId !== '') {
            $deposit = App::db()->fetch(
                'SELECT d.* FROM fund_deposits d
                 JOIN mp_payments p ON p.deposit_id = d.id
                 WHERE p.mp_payment_id = ? AND d.user_id = ?
                 ORDER BY d.id DESC LIMIT 1',
                [$paymentId, auth_id()]
            );
        }

        View::render('wallet/mp_retorno', [
            'title' => 'Resultado del pago',
            'status' => $status,
            'deposit' => $deposit,
            'wallet' => (new \App\Services\WalletService())->ensureWallet(auth_id()),
        ]);
    }

    public function createCharge(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $amount = parse_amount((string) ($_POST['amount'] ?? '0'));
            $charge = (new MpSubAccountService())->createCharge(
                auth_id(),
                $amount,
                trim((string) ($_POST['title'] ?? '')),
                trim((string) ($_POST['note'] ?? ''))
            );
            Session::flash('success', 'Link de cobro generado.');
            redirect(url('/wallet/mp/cobro/' . (int) $charge['id']));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/wallet/mp'));
        }
    }

    public function showCharge(string $id): void
    {
        require_auth();
        $charge = App::db()->fetch(
            'SELECT * FROM mp_charges WHERE id = ? AND user_id = ?',
            [(int) $id, auth_id()]
        );
        if (!$charge) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Cobro no encontrado']);
            return;
        }

        View::render('wallet/mp_cobro', [
            'title' => 'Cobro ' . $charge['code'],
            'charge' => $charge,
        ]);
    }

    public function cancelCharge(string $id): void
    {
        require_auth();
        Csrf::requireValid();
        $charge = App::db()->fetch(
            "SELECT * FROM mp_charges WHERE id = ? AND user_id = ? AND status = 'open'",
            [(int) $id, auth_id()]
        );
        if ($charge) {
            App::db()->update('mp_charges', ['status' => 'cancelled'], 'id = ?', [(int) $charge['id']]);
            Session::flash('success', 'Cobro cancelado.');
        }
        redirect(url('/wallet/mp'));
    }

    /** Página pública que ve quien va a pagar un link de cobro. */
    public function publicCharge(string $ref): void
    {
        $charge = App::db()->fetch(
            'SELECT c.*, u.first_name, u.last_name, u.credimax_id
             FROM mp_charges c JOIN users u ON u.id = c.user_id
             WHERE c.external_reference = ? OR c.code = ?',
            [$ref, strtoupper($ref)]
        );
        if (!$charge) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Cobro no encontrado'], 'layouts/marketing');
            return;
        }

        View::render('wallet/mp_pagar', [
            'title' => 'Pagar a ' . $charge['first_name'],
            'charge' => $charge,
        ], 'layouts/marketing');
    }

    // ------------------------------------------------------------ Vinculación

    public function startLink(): void
    {
        require_auth();
        try {
            redirect((new MpSubAccountService())->startAccountLink(auth_id()));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/wallet/mp'));
        }
    }

    public function finishLink(): void
    {
        require_auth();
        try {
            $code = trim((string) ($_GET['code'] ?? ''));
            $state = trim((string) ($_GET['state'] ?? ''));
            if ($code === '' || $state === '') {
                throw new RuntimeException('Mercado Pago no devolvió el código de autorización.');
            }
            (new MpSubAccountService())->finishAccountLink($code, $state);
            Session::flash('success', 'Tu cuenta de Mercado Pago quedó vinculada.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/wallet/mp'));
    }

    public function unlink(): void
    {
        require_auth();
        Csrf::requireValid();
        (new MpSubAccountService())->unlinkAccount(auth_id());
        Session::flash('success', 'Cuenta de Mercado Pago desvinculada.');
        redirect(url('/wallet/mp'));
    }
}
