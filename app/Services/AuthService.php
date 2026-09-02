<?php
declare(strict_types=1);

/**
 * Password reset + login lock hardening for AuthService.
 * Methods added via patching the existing class below.
 */

namespace App\Services;

use App\Core\App;
use App\Core\Session;
use RuntimeException;

final class AuthService
{
    public function register(array $data): int
    {
        $db = App::db();
        $email = strtolower(trim($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $dni = trim((string) ($data['dni'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email inválido.');
        }
        if (!$this->isStrongPassword($password)) {
            throw new RuntimeException('La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.');
        }
        if ($first === '' || $last === '') {
            throw new RuntimeException('Nombre y apellido son obligatorios.');
        }
        if ($db->fetch('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new RuntimeException('Ya existe una cuenta con ese email.');
        }

        $credimaxId = generate_credimax_id();
        while ($db->fetch('SELECT id FROM users WHERE credimax_id = ?', [$credimaxId])) {
            $credimaxId = generate_credimax_id();
        }

        $userId = $db->insert('users', [
            'credimax_id' => $credimaxId,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'first_name' => $first,
            'last_name' => $last,
            'dni' => $dni !== '' ? $dni : null,
            'phone' => $phone !== '' ? $phone : null,
            'role' => 'user',
            'can_lend' => 1,
            'can_borrow' => 1,
            'kyc_status' => 'pending',
            'status' => 'active',
        ]);

        (new WalletService())->ensureWallet($userId);
        notify($userId, 'Bienvenido a Credimax', 'Tu identidad única es ' . $credimaxId . '. Completá tu verificación para operar créditos.', url('/kyc'));
        audit_log('auth.register', 'user', (string) $userId);

        return $userId;
    }

    public function login(string $email, string $password): void
    {
        $login = strtolower(trim($email));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $max = (int) App::config('security.login_max_attempts', 8);
        $lockMinutes = (int) App::config('security.login_lock_minutes', 15);

        if (!rate_limit_allow('login', max(20, $max * 3), 600)) {
            throw new RuntimeException('Demasiados intentos desde esta red. Esperá unos minutos.');
        }

        $this->ensureLoginAttemptsTable();
        $db = App::db();
        $bucket = hash('sha256', $login . '|' . $ip);
        $row = $db->fetch('SELECT * FROM login_attempts WHERE bucket = ?', [$bucket]);
        $now = time();
        if ($row && !empty($row['locked_until'])) {
            $lockedUntil = strtotime((string) $row['locked_until']) ?: 0;
            if ($lockedUntil > $now) {
                $mins = (int) ceil(($lockedUntil - $now) / 60);
                throw new RuntimeException("Cuenta temporalmente bloqueada. Reintentá en {$mins} min.");
            }
        }

        $attempts = (int) ($row['attempts'] ?? 0);
        if ($row && $attempts >= $max && (empty($row['locked_until']) || (strtotime((string) $row['locked_until']) ?: 0) <= $now)) {
            $until = date('Y-m-d H:i:s', $now + ($lockMinutes * 60));
            $db->update('login_attempts', [
                'attempts' => 0,
                'locked_until' => $until,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'bucket = ?', [$bucket]);
            throw new RuntimeException("Demasiados intentos. Esperá {$lockMinutes} minutos.");
        }

        $user = $db->fetch('SELECT * FROM users WHERE email = ? OR credimax_id = ?', [$login, strtoupper($email)]);
        if (!$user) {
            $user = $db->fetch('SELECT * FROM users WHERE credimax_id = ?', [strtoupper(trim($email))]);
        }
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->bumpLoginAttempt($bucket, $login, $ip, $attempts + 1, $max, $lockMinutes);
            throw new RuntimeException('Credenciales incorrectas.');
        }
        if ($user['status'] !== 'active') {
            throw new RuntimeException('Tu cuenta está suspendida o cerrada.');
        }

        if ($row) {
            $db->query('DELETE FROM login_attempts WHERE bucket = ?', [$bucket]);
        }
        session_regenerate_id(true);
        $this->setSessionUser($user);
        $db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $user['id']]);
        audit_log('auth.login', 'user', (string) $user['id']);
    }

    private function ensureLoginAttemptsTable(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        App::db()->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
              bucket CHAR(64) NOT NULL PRIMARY KEY,
              login_key VARCHAR(190) NOT NULL,
              ip VARCHAR(45) NOT NULL,
              attempts INT UNSIGNED NOT NULL DEFAULT 0,
              locked_until DATETIME NULL,
              updated_at DATETIME NOT NULL,
              INDEX idx_login_ip (ip),
              INDEX idx_login_locked (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $ready = true;
    }

    private function bumpLoginAttempt(
        string $bucket,
        string $login,
        string $ip,
        int $attempts,
        int $max,
        int $lockMinutes
    ): void {
        $db = App::db();
        $now = time();
        $lockedUntil = null;
        if ($attempts >= $max) {
            $lockedUntil = date('Y-m-d H:i:s', $now + ($lockMinutes * 60));
            $attempts = 0;
        }
        $payload = [
            'login_key' => hash('sha256', $login),
            'ip' => substr($ip, 0, 45),
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $exists = $db->fetch('SELECT bucket FROM login_attempts WHERE bucket = ?', [$bucket]);
        if ($exists) {
            // Si ya estaba bloqueado, no borrar locked_until al incrementar fallos previos.
            if ($lockedUntil === null) {
                unset($payload['locked_until']);
            }
            $db->update('login_attempts', $payload, 'bucket = ?', [$bucket]);
        } else {
            $db->insert('login_attempts', array_merge(['bucket' => $bucket], $payload));
        }
        if ($lockedUntil !== null) {
            throw new RuntimeException("Demasiados intentos. Esperá {$lockMinutes} minutos.");
        }
    }

    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email inválido.');
        }
        $user = App::db()->fetch('SELECT * FROM users WHERE email = ?', [$email]);
        // Respuesta uniforme (no filtrar existencia)
        if (!$user || $user['status'] !== 'active') {
            return;
        }

        $token = bin2hex(random_bytes(32));
        App::db()->insert('password_resets', [
            'email' => $email,
            'token' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'used_at' => null,
        ]);

        $link = rtrim((string) App::config('app_url'), '/') . '/reset-password?token=' . urlencode($token);
        $html = '<p>Hola ' . htmlspecialchars((string) $user['first_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Pediste restablecer tu contraseña Credimax.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Restablecer contraseña</a></p>'
            . '<p>El enlace vence en 1 hora. Si no lo pediste, ignorá este mensaje.</p>';
        (new MailService())->send($email, 'Restablecer contraseña Credimax', $html);
        audit_log('auth.password_reset_request', 'user', (string) $user['id']);
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        if (!$this->isStrongPassword($newPassword)) {
            throw new RuntimeException('La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.');
        }
        $hash = hash('sha256', $token);
        $row = App::db()->fetch(
            'SELECT * FROM password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
            [$hash]
        );
        if (!$row) {
            throw new RuntimeException('Enlace inválido o vencido.');
        }
        $user = App::db()->fetch('SELECT * FROM users WHERE email = ?', [$row['email']]);
        if (!$user) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        App::db()->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], 'id = ?', [(int) $user['id']]);
        App::db()->update('password_resets', ['used_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $row['id']]);
        audit_log('auth.password_reset', 'user', (string) $user['id']);
    }

    public function refreshSession(): void
    {
        $id = auth_id();
        if (!$id) {
            return;
        }
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user || $user['status'] !== 'active') {
            $this->logout();
            return;
        }
        $this->setSessionUser($user);
    }

    public function logout(): void
    {
        audit_log('auth.logout', 'user', auth_id() ? (string) auth_id() : null);
        Session::forget('user');
        Session::destroy();
    }

    private function isStrongPassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    private function setSessionUser(array $user): void
    {
        Session::set('user', [
            'id' => (int) $user['id'],
            'credimax_id' => $user['credimax_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'kyc_status' => $user['kyc_status'],
            'can_lend' => (int) $user['can_lend'],
            'can_borrow' => (int) $user['can_borrow'],
        ]);
    }
}
