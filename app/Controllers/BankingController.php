<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\BankingService;
use App\Services\CvuService;
use App\Services\WalletService;

final class BankingController
{
    public function index(): void
    {
        require_auth();
        $bank = new BankingService();
        $accounts = $bank->listAccounts(auth_id());
        $wallet = (new WalletService())->ensureWallet(auth_id());
        View::render('banking/index', [
            'title' => 'Banco Credimax',
            'accounts' => $accounts,
            'wallet' => $wallet,
        ]);
    }

    public function transferForm(): void
    {
        require_auth();
        $wallet = (new WalletService())->ensureWallet(auth_id());
        $beneficiaries = App::db()->fetchAll(
            "SELECT * FROM beneficiaries WHERE user_id = ? AND status = 'active' ORDER BY id DESC",
            [auth_id()]
        );
        View::render('banking/transfer', [
            'title' => 'Transferir CVU/Alias',
            'wallet' => $wallet,
            'beneficiaries' => $beneficiaries,
        ]);
    }

    public function transfer(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $wallet = (new WalletService())->ensureWallet(auth_id());
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            $dest = trim((string) ($_POST['destination'] ?? ''));
            $to = preg_match('/^\d{22}$/', preg_replace('/\D+/', '', $dest) ?? '')
                ? ['cbu' => preg_replace('/\D+/', '', $dest)]
                : ['label' => strtolower($dest)];

            // Consulta de titularidad antes de transferir
            $bank = new BankingService();
            $owner = $bank->getOwnership($to['cbu'] ?? null, $to['label'] ?? null);

            $result = $bank->transfer(auth_id(), (string) $wallet['account_code'], [
                'origin_id' => substr('W' . time(), 0, 15),
                'to' => $to + ['cuit' => $owner['owners'][0]['id'] ?? null],
                'value' => ['currency' => 'ARS', 'amount' => $amount],
                'concept' => 'VAR',
                'description' => trim((string) ($_POST['description'] ?? 'Transferencia')),
            ]);

            if (!empty($_POST['save_beneficiary'])) {
                App::db()->insert('beneficiaries', [
                    'user_id' => auth_id(),
                    'label' => $owner['owners'][0]['display_name'] ?? $dest,
                    'cvu_cbu' => $owner['account_routing']['address'] ?? null,
                    'alias' => $owner['label'] ?? null,
                    'cuit' => $owner['owners'][0]['id'] ?? null,
                    'owner_name' => $owner['owners'][0]['display_name'] ?? null,
                    'status' => 'active',
                ]);
            }

            Session::flash('success', 'Transferencia COMPLETED · ' . $result['id']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/transfer'));
    }

    public function lookup(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $q = trim((string) ($_POST['q'] ?? ''));
            $bank = new BankingService();
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $owner = strlen($digits) === 22
                ? $bank->getOwnership($digits, null)
                : $bank->getOwnership(null, strtolower($q));
            Session::flash('success', 'Titular: ' . ($owner['owners'][0]['display_name'] ?? '') . ' · CVU ' . ($owner['account_routing']['address'] ?? ''));
            Session::set('_lookup', $owner);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/transfer'));
    }

    public function debin(): void
    {
        require_auth();
        $incoming = App::db()->fetchAll(
            "SELECT * FROM debins WHERE buyer_user_id = ? ORDER BY id DESC LIMIT 30",
            [auth_id()]
        );
        $outgoing = App::db()->fetchAll(
            "SELECT * FROM debins WHERE seller_user_id = ? ORDER BY id DESC LIMIT 30",
            [auth_id()]
        );
        $wallet = (new WalletService())->ensureWallet(auth_id());
        View::render('banking/debin', [
            'title' => 'DEBIN',
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'wallet' => $wallet,
        ]);
    }

    public function debinCreate(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $wallet = (new WalletService())->ensureWallet(auth_id());
            $dest = trim((string) ($_POST['destination'] ?? ''));
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            $digits = preg_replace('/\D+/', '', $dest) ?? '';
            $to = strlen($digits) === 22 ? ['cbu' => $digits] : ['label' => strtolower($dest)];
            $result = (new BankingService())->createDebin(auth_id(), (string) $wallet['account_code'], [
                'origin_id' => substr('D' . time(), 0, 15),
                'to' => $to,
                'value' => ['currency' => 'ARS', 'amount' => $amount],
                'concept' => 'VAR',
                'description' => trim((string) ($_POST['description'] ?? 'Cobro DEBIN')),
                'expiration' => (int) ($_POST['expiration'] ?? 60),
            ]);
            Session::flash('success', 'DEBIN creado · ' . $result['id']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/debin'));
    }

    public function debinDecide(string $id): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
            (new BankingService())->resolveDebin(auth_id(), $id, $decision);
            Session::flash('success', 'DEBIN ' . $decision);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/debin'));
    }

    public function echeq(): void
    {
        require_auth();
        $wallet = (new WalletService())->ensureWallet(auth_id());
        $bank = new BankingService();
        $issued = $bank->listEcheqs(auth_id(), (string) $wallet['account_code'], ['status' => 'ACTIVE', 'mode' => 'ISSUER']);
        $received = $bank->listEcheqs(auth_id(), (string) $wallet['account_code'], ['status' => 'ACTIVE', 'mode' => 'RECEIVER']);
        View::render('banking/echeq', [
            'title' => 'ECHEQ',
            'wallet' => $wallet,
            'issued' => $issued,
            'received' => $received,
        ]);
    }

    public function echeqIssue(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $wallet = (new WalletService())->ensureWallet(auth_id());
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            $dest = trim((string) ($_POST['destination'] ?? ''));
            $digits = preg_replace('/\D+/', '', $dest) ?? '';
            $data = [
                'amount' => $amount,
                'payment_date' => $_POST['payment_date'] ?? date('Y-m-d', strtotime('+30 days')),
                'receiver_name' => trim((string) ($_POST['receiver_name'] ?? '')),
                'receiver_cuit' => trim((string) ($_POST['receiver_cuit'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? 'ECHEQ')),
                'check_type' => $_POST['check_type'] ?? 'CPD',
            ];
            if (strlen($digits) === 22) {
                $data['receiver_cvu'] = $digits;
            } elseif ($dest !== '') {
                $data['receiver_alias'] = strtolower($dest);
            }
            $r = (new BankingService())->issueEcheq(auth_id(), (string) $wallet['account_code'], $data);
            Session::flash('success', 'ECHEQ emitido · ' . $r['id']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/echeq'));
    }

    public function echeqAction(string $id): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            (new BankingService())->echeqAction(auth_id(), $id, (string) ($_POST['action'] ?? ''));
            Session::flash('success', 'Acción aplicada.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/echeq'));
    }

    public function aliasForm(): void
    {
        require_auth();
        $wallet = (new WalletService())->ensureWallet(auth_id());
        View::render('banking/alias', ['title' => 'Alias CVU', 'wallet' => $wallet]);
    }

    public function aliasSave(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            (new CvuService())->changeAlias(auth_id(), (string) ($_POST['alias'] ?? ''));
            Session::flash('success', 'Alias actualizado.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/banking/alias'));
    }

    public function apiKeys(): void
    {
        require_auth();
        $keys = App::db()->fetchAll(
            'SELECT id, name, api_key_prefix, created_at, last_used_at, revoked_at FROM api_credentials WHERE user_id = ? ORDER BY id DESC',
            [auth_id()]
        );
        View::render('banking/api_keys', [
            'title' => 'Credenciales API',
            'keys' => $keys,
            'newKey' => Session::flash('new_api_key'),
        ]);
    }

    public function apiKeyCreate(): void
    {
        require_auth();
        Csrf::requireValid();
        $plain = 'cmx_live_' . bin2hex(random_bytes(24));
        $prefix = substr($plain, 0, 12);
        App::db()->insert('api_credentials', [
            'user_id' => auth_id(),
            'name' => trim((string) ($_POST['name'] ?? 'API Key')),
            'api_key_prefix' => $prefix,
            'api_key_hash' => password_hash($plain, PASSWORD_DEFAULT),
            'scopes' => 'accounts,transfers,debin,echeq',
        ]);
        Session::flash('new_api_key', $plain);
        Session::flash('success', 'Guardá la API key ahora: solo se muestra una vez.');
        redirect(url('/banking/api-keys'));
    }
}
