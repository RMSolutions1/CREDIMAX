<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\WalletService;

final class WalletController
{
    public function index(): void
    {
        require_auth();
        $uid = auth_id();
        $wallet = (new WalletService())->ensureWallet($uid);
        $txs = App::db()->fetchAll(
            'SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$uid]
        );
        View::render('wallet/index', [
            'title' => 'Billetera',
            'wallet' => $wallet,
            'txs' => $txs,
        ]);
    }

    public function deposit(): void
    {
        require_auth();
        Csrf::requireValid();
        // Los depósitos de usuarios van a tesorería Credimax (confirmación admin)
        try {
            $amount = parse_amount((string) ($_POST['amount'] ?? '0'));
            (new \App\Services\TreasuryService())->requestDeposit(
                auth_id(),
                $amount,
                'transfer',
                trim((string) ($_POST['reference'] ?? ''))
            );
            Session::flash('success', 'Depósito informado a Credimax. Se acreditará al confirmar la recepción.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/funds'));
    }

    public function withdraw(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $amount = parse_amount((string) ($_POST['amount'] ?? '0'));
            otp_redirect_if_needed('wallet:withdraw:' . $amount, $amount, url('/wallet'), [
                'cbu' => (string) ($_POST['cbu'] ?? ''),
                'alias' => (string) ($_POST['alias'] ?? ''),
                'holder' => (string) ($_POST['holder'] ?? ''),
            ]);
            (new \App\Services\TreasuryService())->requestWithdraw(
                auth_id(),
                $amount,
                (string) ($_POST['cbu'] ?? ''),
                (string) ($_POST['alias'] ?? ''),
                (string) ($_POST['holder'] ?? '')
            );
            Session::flash('success', 'Retiro solicitado. El saldo quedó reservado hasta la transferencia bancaria.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/wallet'));
    }

    public function transfer(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $amount = parse_amount((string) ($_POST['amount'] ?? '0'));
            $target = trim((string) ($_POST['target'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));

            $user = App::db()->fetch(
                'SELECT id FROM users WHERE credimax_id = ? OR email = ? LIMIT 1',
                [$target, strtolower($target)]
            );
            if (!$user) {
                throw new \RuntimeException('Destinatario no encontrado. Usá Credimax ID o email.');
            }
            otp_redirect_if_needed('wallet:transfer:' . (int) $user['id'] . ':' . $amount, $amount, url('/wallet'), [
                'target' => $target,
                'note' => $note,
            ]);
            $result = (new WalletService())->transfer(
                auth_id(),
                (int) $user['id'],
                $amount,
                $note,
                idem_key('transfer')
            );
            Session::flash('success', !empty($result['duplicate'])
                ? 'Esta transferencia ya se había enviado; no se duplicó.'
                : 'Transferencia enviada correctamente.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/wallet'));
    }

    public function qr(): void
    {
        require_auth();
        $wallet = (new WalletService())->ensureWallet(auth_id());
        $payload = json_encode([
            'v' => 1,
            'app' => 'credimax',
            'id' => auth_user()['credimax_id'],
            'cvu' => $wallet['cvu'],
            'alias' => $wallet['alias'],
            'token' => $wallet['qr_token'],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $payload = '';
        }

        View::render('wallet/qr', [
            'title' => 'Mi QR Credimax',
            'wallet' => $wallet,
            'payload' => $payload,
        ]);
    }

    public function payQr(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $token = trim((string) ($_POST['qr_token'] ?? ''));
            $rawPayload = trim((string) ($_POST['qr_payload'] ?? ''));

            if ($token === '' && $rawPayload !== '') {
                $decoded = json_decode($rawPayload, true);
                if (is_array($decoded) && !empty($decoded['token'])) {
                    $token = (string) $decoded['token'];
                } else {
                    $digits = preg_replace('/\D+/', '', $rawPayload) ?? '';
                    if (strlen($digits) === 22) {
                        $ownerWallet = App::db()->fetch('SELECT * FROM wallets WHERE cvu = ? OR cbu = ?', [$digits, $digits]);
                        if ($ownerWallet) {
                            $token = (string) $ownerWallet['qr_token'];
                        }
                    } elseif (str_contains($rawPayload, '.')) {
                        $ownerWallet = App::db()->fetch('SELECT * FROM wallets WHERE alias = ?', [strtolower($rawPayload)]);
                        if ($ownerWallet) {
                            $token = (string) $ownerWallet['qr_token'];
                        }
                    } else {
                        $token = $rawPayload;
                    }
                }
            }

            if ($token === '') {
                throw new \RuntimeException('Destino QR/CVU/alias inválido.');
            }

            $amount = parse_amount((string) ($_POST['amount'] ?? '0'));
            $note = trim((string) ($_POST['note'] ?? ''));
            $result = (new WalletService())->payByQr(auth_id(), $token, $amount, $note, idem_key('qr'));
            Session::flash('success', !empty($result['duplicate'])
                ? 'Este pago ya se había realizado; no se duplicó.'
                : 'Pago QR realizado. Ref: ' . ($result['qr_reference'] ?? ''));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/wallet/qr'));
    }
}
