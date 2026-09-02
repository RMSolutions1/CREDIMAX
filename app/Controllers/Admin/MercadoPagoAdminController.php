<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\MercadoPagoService;
use App\Services\MpSubAccountService;

/**
 * Panel de operación de Mercado Pago: credenciales, salud de la integración,
 * conciliación cuenta madre ↔ ledger, devoluciones y órdenes de pago (cash-out).
 */
final class MercadoPagoAdminController
{
    /** Claves que se guardan en settings; las sensibles nunca se re-imprimen. */
    private const KEYS = [
        'mp_enabled' => false,
        'mp_access_token' => true,
        'mp_public_key' => false,
        'mp_client_id' => false,
        'mp_client_secret' => true,
        'mp_webhook_secret' => true,
        'mp_site_id' => false,
    ];

    public function index(): void
    {
        require_admin();
        $mp = new MercadoPagoService();
        $db = App::db();

        $account = null;
        $accountError = null;
        if ($mp->isConfigured()) {
            $result = $mp->me();
            if ($result['ok']) {
                $account = $result['data'];
            } else {
                $accountError = $result['error'];
            }
        }

        View::render('admin/mercadopago', [
            'title' => 'Mercado Pago',
            'configured' => $mp->isConfigured(),
            'enabled' => $mp->isEnabled(),
            'sandbox' => $mp->isSandbox(),
            'hasWebhookSecret' => $mp->webhookSecret() !== '',
            'hasOauth' => $mp->clientId() !== '' && $mp->clientSecret() !== '',
            'siteId' => $mp->siteId(),
            'account' => $account,
            'accountError' => $accountError,
            'webhookUrl' => absolute_url('/webhooks/mercadopago'),
            'redirectUri' => (new MpSubAccountService())->redirectUri(),
            'settings' => $this->maskedSettings(),
            'stats' => $this->stats(),
            'recentPayments' => $db->fetchAll(
                'SELECT p.*, u.credimax_id, u.email
                 FROM mp_payments p LEFT JOIN users u ON u.id = p.user_id
                 ORDER BY p.id DESC LIMIT 30'
            ),
            'events' => $db->fetchAll('SELECT * FROM mp_webhook_events ORDER BY id DESC LIMIT 25'),
            'payouts' => $db->fetchAll(
                "SELECT po.*, u.credimax_id, u.email
                 FROM mp_payouts po JOIN users u ON u.id = po.user_id
                 WHERE po.status = 'queued' ORDER BY po.id ASC LIMIT 50"
            ),
        ]);
    }

    public function saveSettings(): void
    {
        require_admin();
        Csrf::requireValid();

        $db = App::db();
        foreach (self::KEYS as $key => $sensitive) {
            $short = substr($key, 3);
            if (!array_key_exists($short, $_POST)) {
                continue;
            }
            $value = trim((string) $_POST[$short]);
            // Un campo sensible vacío significa "no cambiar", no "borrar".
            if ($sensitive && $value === '') {
                continue;
            }
            if ($key === 'mp_enabled') {
                $value = isset($_POST['enabled']) ? '1' : '0';
            }
            // El access token controla todo el dinero de la cuenta madre: nunca en claro.
            $this->putSetting($key, $sensitive ? Crypto::encrypt($value) : $value);
        }
        // El checkbox no llega en el POST cuando está desmarcado.
        $this->putSetting('mp_enabled', isset($_POST['enabled']) ? '1' : '0');

        audit_log('mp.settings_saved', 'settings', 'mercadopago');
        Session::flash('success', 'Configuración de Mercado Pago guardada.');
        redirect(url('/admin/mercadopago'));
    }

    public function reconcile(): void
    {
        require_admin();
        Csrf::requireValid();
        try {
            $days = max(1, min(30, (int) ($_POST['days'] ?? 3)));
            $result = (new MpSubAccountService())->reconcile(
                date('c', strtotime('-' . $days . ' days')),
                date('c')
            );
            Session::flash('success', sprintf(
                'Conciliación: %d pagos revisados, %d sin acreditar, %d reparados. Total aprobado %s.',
                $result['checked'],
                count($result['uncredited']),
                $result['repaired'],
                money($result['approved_total'])
            ));
            audit_log('mp.reconcile', 'settings', 'mercadopago', $result);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/mercadopago'));
    }

    /** Fuerza la relectura de un pago puntual desde Mercado Pago. */
    public function syncPayment(string $paymentId): void
    {
        require_admin();
        Csrf::requireValid();
        // Permite POST con payment_id en el body (formulario del panel).
        if ($paymentId === '' || $paymentId === '0') {
            $paymentId = trim((string) ($_POST['payment_id'] ?? ''));
        }
        try {
            if ($paymentId === '' || !ctype_digit($paymentId)) {
                throw new \RuntimeException('Indicá un payment_id numérico de Mercado Pago.');
            }
            $result = (new MpSubAccountService())->syncPayment($paymentId);
            Session::flash($result['credited'] ? 'success' : 'error', $result['reason']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/mercadopago'));
    }

    public function refund(string $id): void
    {
        require_admin();
        Csrf::requireValid();
        try {
            $amount = trim((string) ($_POST['amount'] ?? ''));
            (new MpSubAccountService())->refundTopup(
                (int) $id,
                $amount === '' ? null : parse_amount($amount),
                (int) auth_id()
            );
            Session::flash('success', 'Devolución enviada a Mercado Pago.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/mercadopago'));
    }

    public function markPayoutSent(string $id): void
    {
        require_admin();
        Csrf::requireValid();
        try {
            (new MpSubAccountService())->markPayoutSent(
                (int) $id,
                (int) auth_id(),
                trim((string) ($_POST['operation_id'] ?? '')),
                trim((string) ($_POST['notes'] ?? ''))
            );
            Session::flash('success', 'Orden de pago marcada como transferida.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/mercadopago'));
    }

    /** Exporta las órdenes de pago pendientes para cargarlas en Mercado Pago. */
    public function exportPayouts(): void
    {
        require_admin();
        $rows = App::db()->fetchAll(
            "SELECT po.*, u.credimax_id, u.dni FROM mp_payouts po
             JOIN users u ON u.id = po.user_id WHERE po.status = 'queued' ORDER BY po.id ASC"
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="credimax-payouts-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['orden', 'credimax_id', 'documento', 'tipo_destino', 'destino', 'titular', 'importe']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['credimax_id'],
                $r['dni'],
                $r['destination_type'],
                $r['destination'],
                $r['holder'],
                number_format((float) $r['amount'], 2, '.', ''),
            ]);
        }
        fclose($out);
        audit_log('mp.payouts_export', 'mp_payout', null, ['count' => count($rows)]);
        exit;
    }

    // ---------------------------------------------------------------- Helpers

    private function stats(): array
    {
        $db = App::db();
        return [
            'credited_total' => (float) ($db->fetch(
                "SELECT COALESCE(SUM(amount),0) s FROM mp_payments WHERE credited = 1"
            )['s'] ?? 0),
            'approved_uncredited' => (int) ($db->fetch(
                "SELECT COUNT(*) c FROM mp_payments WHERE status = 'approved' AND credited = 0"
            )['c'] ?? 0),
            'pending_payouts' => (float) ($db->fetch(
                "SELECT COALESCE(SUM(amount),0) s FROM mp_payouts WHERE status = 'queued'"
            )['s'] ?? 0),
            'failed_events' => (int) ($db->fetch(
                "SELECT COUNT(*) c FROM mp_webhook_events WHERE processed = 0 AND error IS NOT NULL"
            )['c'] ?? 0),
            'linked_accounts' => (int) ($db->fetch(
                "SELECT COUNT(*) c FROM mp_subaccounts WHERE status = 'linked'"
            )['c'] ?? 0),
            'customer_ledger' => (float) ($db->fetch(
                "SELECT COALESCE(SUM(w.balance),0) s FROM wallets w
                 JOIN users u ON u.id = w.user_id WHERE u.role = 'user'"
            )['s'] ?? 0),
        ];
    }

    /** @return array<string,string> Valores no sensibles + marca de "cargado" en los secretos. */
    private function maskedSettings(): array
    {
        $out = [];
        $rows = App::db()->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mp_%'");
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $value = (string) ($row['setting_value'] ?? '');
            $out[substr($key, 3)] = (self::KEYS[$key] ?? false) && $value !== ''
                ? '•••••• (cargado)'
                : $value;
        }
        return $out;
    }

    private function putSetting(string $key, string $value): void
    {
        $db = App::db();
        $exists = $db->fetch('SELECT id FROM settings WHERE setting_key = ?', [$key]);
        if ($exists) {
            $db->update('settings', ['setting_value' => $value], 'id = ?', [(int) $exists['id']]);
        } else {
            $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
        }
    }
}
