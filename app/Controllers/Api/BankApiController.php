<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\View;
use App\Services\AuthService;
use App\Services\BankingService;
use App\Services\CvuService;
use App\Services\JwtService;
use App\Services\WalletService;
use RuntimeException;

/**
 * API REST privada Credimax Bank.
 * Base: /api/v1 — 100% interna, liquidación en ledger propio.
 */
final class BankApiController
{
    private BankingService $bank;
    private JwtService $jwt;

    public function __construct()
    {
        $this->bank = new BankingService();
        $this->jwt = new JwtService();
    }

    public function login(): void
    {
        if (!rate_limit_allow('api_login', 20, 600)) {
            View::json(['error' => 'rate_limited'], 429);
            return;
        }
        $body = $this->jsonBody();
        $username = (string) ($body['username'] ?? $body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        try {
            (new AuthService())->login($username, $password);
            $user = App::db()->fetch('SELECT * FROM users WHERE email = ?', [strtolower(trim($username))]);
            if (!$user) {
                // login by credimax_id
                $user = App::db()->fetch('SELECT * FROM users WHERE credimax_id = ? OR email = ?', [$username, strtolower(trim($username))]);
            }
            // AuthService already set session; reload user
            $uid = auth_id();
            $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$uid]);
            $ttl = (int) (App::db()->fetch("SELECT setting_value FROM settings WHERE setting_key='jwt_ttl_seconds'")['setting_value'] ?? 3600);
            $token = $this->jwt->issue($user, $ttl);
            // Also allow login without session pollution for pure API: ok
            View::json([
                'token' => $token['token'],
                'expires_in' => $token['expires_in'],
                'token_type' => 'JWT',
                'bank_id' => $this->bank->bankId(),
            ]);
        } catch (\Throwable $e) {
            $this->error('auth_failed', $e->getMessage(), 401);
        }
    }

    public function listAccounts(string $bankId, string $viewId): void
    {
        $user = $this->authUser();
        $this->assertBank($bankId);
        $accounts = $this->bank->listAccounts((int) $user['id']);
        View::json($accounts);
    }

    public function transactions(string $bankId, string $accountId, string $viewId): void
    {
        $user = $this->authUser();
        $this->assertBank($bankId);
        $mov = $this->bank->getMovements((int) $user['id'], $accountId, [
            'from' => $_SERVER['HTTP_OBP_FROM_DATE'] ?? $_GET['from'] ?? null,
            'to' => $_SERVER['HTTP_OBP_TO_DATE'] ?? $_GET['to'] ?? null,
            'limit' => $_SERVER['HTTP_OBP_LIMIT'] ?? $_GET['limit'] ?? 25,
            'offset' => $_SERVER['HTTP_OBP_OFFSET'] ?? $_GET['offset'] ?? 1,
        ]);
        View::json($mov);
    }

    public function ownershipByCbu(string $cbu): void
    {
        $this->authUser();
        try {
            View::json($this->bank->getOwnership($cbu, null));
        } catch (RuntimeException $e) {
            $this->error('not_found', $e->getMessage(), $e->getCode() ?: 404);
        }
    }

    public function ownershipByAlias(string $alias): void
    {
        $this->authUser();
        try {
            View::json($this->bank->getOwnership(null, urldecode($alias)));
        } catch (RuntimeException $e) {
            $this->error('not_found', $e->getMessage(), $e->getCode() ?: 404);
        }
    }

    public function transfer(string $bankId, string $accountId, string $viewId): void
    {
        $user = $this->authUser();
        $this->assertBank($bankId);
        try {
            $result = $this->bank->transfer((int) $user['id'], $accountId, $this->jsonBody());
            View::json($result, 201);
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function debin(string $bankId, string $accountId, string $viewId): void
    {
        $user = $this->authUser();
        $this->assertBank($bankId);
        try {
            $result = $this->bank->createDebin((int) $user['id'], $accountId, $this->jsonBody());
            View::json($result, 201);
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function debinApprove(string $debinId): void
    {
        $user = $this->authUser();
        try {
            View::json($this->bank->resolveDebin((int) $user['id'], $debinId, 'approve'));
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function debinReject(string $debinId): void
    {
        $user = $this->authUser();
        try {
            View::json($this->bank->resolveDebin((int) $user['id'], $debinId, 'reject'));
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function listEcheqs(string $bankId, string $accountId, string $viewId): void
    {
        $user = $this->authUser();
        $this->assertBank($bankId);
        $status = $_SERVER['HTTP_OBP_STATUS'] ?? $_GET['status'] ?? 'ACTIVE';
        try {
            View::json($this->bank->listEcheqs((int) $user['id'], $accountId, [
                'status' => $status,
                'mode' => $_SERVER['HTTP_OBP_MODE'] ?? $_GET['mode'] ?? 'ISSUER',
            ]));
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function issueEcheq(string $bankId, string $accountId, string $viewId): void
    {
        $user = $this->authUser();
        $this->assertBank($bankId);
        try {
            View::json($this->bank->issueEcheq((int) $user['id'], $accountId, $this->jsonBody()), 201);
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function echeqAction(string $echeqId, string $action): void
    {
        $user = $this->authUser();
        try {
            View::json($this->bank->echeqAction((int) $user['id'], $echeqId, $action));
        } catch (RuntimeException $e) {
            $this->error('api_error', $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    public function changeAlias(): void
    {
        $user = $this->authUser();
        $body = $this->jsonBody();
        try {
            $w = (new CvuService())->changeAlias((int) $user['id'], (string) ($body['alias'] ?? ''));
            View::json([
                'alias' => $w['alias'],
                'cvu' => $w['cvu'],
                'account_code' => $w['account_code'],
            ]);
        } catch (RuntimeException $e) {
            $this->error('validation', $e->getMessage(), 422);
        }
    }

    public function me(): void
    {
        $user = $this->authUser();
        $accounts = $this->bank->listAccounts((int) $user['id']);
        View::json([
            'user' => [
                'id' => (int) $user['id'],
                'credimax_id' => $user['credimax_id'],
                'email' => $user['email'],
                'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                'role' => $user['role'],
            ],
            'accounts' => $accounts,
            'network' => [
                'name' => 'Credimax Bank Privado',
                'bank_id' => $this->bank->bankId(),
                'external_dependencies' => [],
                'private' => true,
            ],
        ]);
    }

    public function health(): void
    {
        $start = microtime(true);
        $status = 'pass';
        $httpCode = 200;
        $checks = [];

        try {
            $db = App::db();
            $v = $db->fetch('SELECT 1 AS ok, NOW() AS n');
            $checks['db'] = ['status' => 'pass', 'now' => (string) ($v['n'] ?? '')];
        } catch (\Throwable $e) {
            $checks['db'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $status = 'fail';
            $httpCode = 503;
        }

        $storageOk = false;
        try {
            $dir = CREDIMAX_ROOT . '/storage/uploads/tmp';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $tmp = $dir . '/health-' . bin2hex(random_bytes(4)) . '.tmp';
            $payload = 'health-ok-' . time();
            $w = @file_put_contents($tmp, $payload);
            if ($w !== false) {
                $r = @file_get_contents($tmp);
                @unlink($tmp);
                $storageOk = $r === $payload;
            }
            $checks['storage'] = ['status' => $storageOk ? 'pass' : 'fail'];
        } catch (\Throwable $e) {
            $checks['storage'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $status = 'fail';
            $httpCode = 503;
        }

        try {
            $ledger = ['aum' => null, 'wallets' => null, 'pending_withdrawals' => null];
            if (!empty($checks['db']['status']) && $checks['db']['status'] === 'pass') {
                $db = App::db();
                $aumRow = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'aum_ars'");
                $wallets = $db->fetch('SELECT COALESCE(SUM(balance),0) AS total FROM wallets');
                $pending = $db->fetch("SELECT COUNT(*) AS n FROM withdraw_requests WHERE status = 'pending'");
                $ledger['aum'] = $aumRow ? (float) $aumRow['setting_value'] : null;
                $ledger['wallets'] = (float) ($wallets['total'] ?? 0.0);
                $ledger['pending_withdrawals'] = (int) ($pending['n'] ?? 0);
            }
            $checks['ledger'] = ['status' => 'pass'] + $ledger;
        } catch (\Throwable $e) {
            $checks['ledger'] = ['status' => 'warn', 'error' => $e->getMessage()];
        }

        try {
            $redisOk = true;
            if (!\App\Services\RedisService::isAvailable()) {
                $checks['redis'] = ['status' => 'warn', 'note' => 'not_configured'];
            } else {
                $ok = \App\Services\RedisService::ping();
                $checks['redis'] = ['status' => $ok ? 'pass' : 'fail'];
                if (!$ok) {
                    $status = $status === 'pass' ? 'warn' : $status;
                }
            }
        } catch (\Throwable $e) {
            $checks['redis'] = ['status' => 'warn', 'error' => $e->getMessage()];
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $out = [
            'status' => $status,
            'version' => (string) (defined('CREDIMAX_VERSION') ? CREDIMAX_VERSION : '1.0.0'),
            'env' => (string) App::config('app_env', 'production'),
            'timestamp' => date('c'),
            'response_ms' => $ms,
            'aum_ars' => $checks['ledger']['aum'] ?? null,
            'checks' => $checks,
        ];
        View::json($out, $httpCode);
    }

    public function docs(): void
    {
        View::render('api/docs', ['title' => 'API Credimax Bank'], 'layouts/marketing');
    }

    private function authUser(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        $header = trim($header);

        // API Key: Authorization: ApiKey cmx_live_...
        if (preg_match('/^ApiKey\s+(.+)$/i', $header, $m)) {
            $plain = trim($m[1]);
            $prefix = substr($plain, 0, 12);
            $rows = App::db()->fetchAll(
                'SELECT * FROM api_credentials WHERE api_key_prefix = ? AND revoked_at IS NULL',
                [$prefix]
            );
            foreach ($rows as $row) {
                if (password_verify($plain, $row['api_key_hash'])) {
                    App::db()->update('api_credentials', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $row['id']]);
                    $user = App::db()->fetch('SELECT * FROM users WHERE id = ? AND status = ?', [(int) $row['user_id'], 'active']);
                    if ($user) {
                        return $user;
                    }
                }
            }
            $this->error('auth_failed', 'API Key inválida.', 401);
        }

        if (!preg_match('/^(JWT|Bearer)\s+(.+)$/i', $header, $m)) {
            if (auth_user()) {
                $u = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
                if ($u) {
                    return $u;
                }
            }
            $this->error('auth_failed', 'Authorization JWT o ApiKey requerida.', 401);
        }
        try {
            $payload = $this->jwt->decode(trim($m[2]));
            $user = App::db()->fetch('SELECT * FROM users WHERE id = ? AND status = ?', [(int) $payload['sub'], 'active']);
            if (!$user) {
                $this->error('auth_failed', 'Usuario inválido.', 401);
            }
            return $user;
        } catch (\Throwable $e) {
            $this->error('auth_failed', $e->getMessage(), 401);
        }
    }

    private function assertBank(string $bankId): void
    {
        if ($bankId !== $this->bank->bankId() && $bankId !== '900') {
            $this->error('validation', 'bank_id inválido para red Credimax.', 404);
        }
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '' && !empty($_POST)) {
            return $_POST;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function error(string $code, string $message, int $status = 400): never
    {
        View::json(['ok' => false, 'error' => $code, 'message' => $message], $status);
        exit;
    }
}
