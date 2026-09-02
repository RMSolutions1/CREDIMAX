<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\View;
use App\Services\MercadoPagoService;
use App\Services\MpSubAccountService;

/**
 * Receptor de notificaciones (webhooks) de Mercado Pago.
 *
 * Reglas que hacen segura la acreditación:
 *  1. La firma x-signature se valida por HMAC antes de tocar nada.
 *  2. El cuerpo del webhook nunca se cree: siempre se relee el pago desde la API.
 *  3. Cada evento se registra con clave única, así los reintentos de Mercado Pago
 *     no vuelven a acreditar un pago ya procesado.
 */
final class MpWebhookController
{
    public function handle(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];

        $type = (string) ($_GET['type'] ?? $_GET['topic'] ?? $body['type'] ?? $body['topic'] ?? '');
        $action = (string) ($body['action'] ?? '');
        $dataId = (string) ($_GET['data.id'] ?? $_GET['id'] ?? $body['data']['id'] ?? '');

        $xSignature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
        $xRequestId = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');

        $mp = new MercadoPagoService();
        $signatureValid = $mp->verifyWebhookSignature($xSignature, $xRequestId, $dataId);

        // En producción el secreto es obligatorio: sin él o con firma inválida no se procesa nada.
        if (App::config('app_env') === 'production') {
            if ($mp->webhookSecret() === '') {
                $this->log($type, $action, $dataId, false, 'webhook_secret ausente en producción', $raw);
                View::json(['error' => 'webhook_not_configured'], 503);
                return;
            }
            if (!$signatureValid) {
                $this->log($type, $action, $dataId, false, 'Firma x-signature inválida', $raw);
                View::json(['error' => 'invalid_signature'], 401);
                return;
            }
        } elseif ($mp->webhookSecret() !== '' && !$signatureValid) {
            // En local, si ya hay secreto configurado, también se exige la firma.
            $this->log($type, $action, $dataId, false, 'Firma x-signature inválida', $raw);
            View::json(['error' => 'invalid_signature'], 401);
            return;
        }

        if ($dataId === '') {
            View::json(['status' => 'ignored', 'reason' => 'sin data.id'], 200);
            return;
        }

        $eventKey = substr($type . ':' . $action . ':' . $dataId . ':' . $xRequestId, 0, 190);
        $db = App::db();
        $existing = $db->fetch('SELECT id, processed FROM mp_webhook_events WHERE event_key = ?', [$eventKey]);
        if ($existing && (int) $existing['processed'] === 1) {
            View::json(['status' => 'duplicate'], 200);
            return;
        }

        $eventId = $existing
            ? (int) $existing['id']
            : $this->log($type, $action, $dataId, $signatureValid, null, $raw);

        try {
            $result = $this->process($type, $dataId);
            $db->update('mp_webhook_events', [
                'processed' => 1,
                'result' => substr((string) ($result['reason'] ?? ''), 0, 255),
                'processed_at' => date('Y-m-d H:i:s'),
                'attempts' => (int) ($existing['attempts'] ?? 0) + 1,
            ], 'id = ?', [$eventId]);

            View::json(['status' => 'ok'], 200);
        } catch (\Throwable $e) {
            $db->update('mp_webhook_events', [
                'error' => substr($e->getMessage(), 0, 255),
                'attempts' => (int) ($existing['attempts'] ?? 0) + 1,
            ], 'id = ?', [$eventId]);
            error_log('Webhook Mercado Pago: ' . $e->getMessage());

            // 500 hace que Mercado Pago reintente la notificación.
            View::json(['error' => 'processing_failed'], 500);
        }
    }

    /** @return array{handled:bool,reason:string,credited:bool} */
    private function process(string $type, string $dataId): array
    {
        $service = new MpSubAccountService();

        return match ($type) {
            'payment' => $service->syncPayment($dataId),
            'merchant_order' => $this->processMerchantOrder($service, $dataId),
            default => ['handled' => false, 'reason' => 'Tópico no manejado: ' . $type, 'credited' => false],
        };
    }

    private function processMerchantOrder(MpSubAccountService $service, string $orderId): array
    {
        $order = $service->mp()->getMerchantOrder($orderId);
        if (!$order['ok']) {
            return ['handled' => false, 'reason' => 'Orden no legible', 'credited' => false];
        }
        $credited = false;
        foreach ($order['data']['payments'] ?? [] as $payment) {
            if (!empty($payment['id'])) {
                $result = $service->syncPayment((string) $payment['id']);
                $credited = $credited || $result['credited'];
            }
        }
        return ['handled' => true, 'reason' => 'Orden procesada', 'credited' => $credited];
    }

    private function log(string $type, string $action, string $dataId, bool $valid, ?string $error, string $raw): int
    {
        return App::db()->insert('mp_webhook_events', [
            'event_key' => substr($type . ':' . $action . ':' . $dataId . ':' . (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''), 0, 190),
            'type' => substr($type, 0, 40),
            'action' => substr($action, 0, 60),
            'data_id' => substr($dataId, 0, 60),
            'signature_valid' => $valid ? 1 : 0,
            'payload' => substr($raw, 0, 6000),
            'error' => $error,
            'processed' => 0,
            'attempts' => 0,
        ]);
    }
}
