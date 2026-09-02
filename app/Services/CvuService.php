<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * Generador de CVU/CBU e identidad bancaria privada Credimax (entity 900).
 * Red 100% privada Credimax: no consulta ni depende de redes o APIs externas.
 */
final class CvuService
{
    public const ENTITY = '900';

    public function ensureBankIdentity(int $userId, array $wallet, ?string $cuit = null): array
    {
        $db = App::db();
        $updates = [];

        if (empty($wallet['account_code'])) {
            $updates['account_code'] = sprintf('90-0-%04d-0-1', (int) $wallet['id']);
        }
        if (empty($wallet['cvu'])) {
            $updates['cvu'] = $this->generateCvu((int) $wallet['id']);
            $updates['cbu'] = $updates['cvu']; // en red privada CVU=CBU Credimax
        }
        if (empty($wallet['alias'])) {
            $updates['alias'] = $this->generateAlias($userId);
        }
        if ($cuit && empty($wallet['cuit'])) {
            $updates['cuit'] = preg_replace('/\D+/', '', $cuit);
        }
        if (!$updates) {
            return $wallet;
        }
        $db->update('wallets', $updates, 'id = ?', [(int) $wallet['id']]);
        return $db->fetch('SELECT * FROM wallets WHERE id = ?', [(int) $wallet['id']]) ?? $wallet;
    }

    public function generateCvu(int $walletId): string
    {
        // 22 dígitos: 900 + 0001 + wallet(10) + random(4) + check(1)
        $body = self::ENTITY
            . '0001'
            . str_pad((string) $walletId, 10, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $check = $this->mod10Check($body);
        $cvu = $body . $check;

        $db = App::db();
        while ($db->fetch('SELECT id FROM wallets WHERE cvu = ?', [$cvu])) {
            $body = self::ENTITY
                . '0001'
                . str_pad((string) $walletId, 10, '0', STR_PAD_LEFT)
                . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $cvu = $body . $this->mod10Check($body);
        }
        return $cvu;
    }

    public function generateAlias(int $userId): string
    {
        $base = 'credimax.' . strtolower(bin2hex(random_bytes(3)));
        $db = App::db();
        $alias = $base;
        $i = 0;
        while ($db->fetch('SELECT id FROM wallets WHERE alias = ?', [$alias])) {
            $i++;
            $alias = $base . $i;
        }
        return $alias;
    }

    public function changeAlias(int $userId, string $alias): array
    {
        $alias = strtolower(trim($alias));
        if (!preg_match('/^[a-z0-9][a-z0-9.\-_]{5,39}$/', $alias)) {
            throw new RuntimeException('Alias inválido (6-40 chars, alfanumérico).');
        }
        $db = App::db();
        $taken = $db->fetch('SELECT id, user_id FROM wallets WHERE alias = ?', [$alias]);
        if ($taken && (int) $taken['user_id'] !== $userId) {
            throw new RuntimeException('Alias no disponible.');
        }
        $wallet = $db->fetch('SELECT * FROM wallets WHERE user_id = ?', [$userId]);
        if (!$wallet) {
            throw new RuntimeException('Cuenta no encontrada.');
        }
        $db->update('wallets', ['alias' => $alias], 'id = ?', [(int) $wallet['id']]);
        return $db->fetch('SELECT * FROM wallets WHERE id = ?', [(int) $wallet['id']]);
    }

    public function findByCvuOrAlias(?string $cvu = null, ?string $alias = null): ?array
    {
        $db = App::db();
        if ($cvu) {
            $cvu = preg_replace('/\D+/', '', $cvu);
            return $db->fetch(
                'SELECT w.*, u.credimax_id, u.first_name, u.last_name, u.email, u.dni, u.status AS user_status
                 FROM wallets w JOIN users u ON u.id = w.user_id WHERE w.cvu = ? OR w.cbu = ? LIMIT 1',
                [$cvu, $cvu]
            );
        }
        if ($alias) {
            return $db->fetch(
                'SELECT w.*, u.credimax_id, u.first_name, u.last_name, u.email, u.dni, u.status AS user_status
                 FROM wallets w JOIN users u ON u.id = w.user_id WHERE w.alias = ? LIMIT 1',
                [strtolower(trim($alias))]
            );
        }
        return null;
    }

    private function mod10Check(string $digits): string
    {
        $sum = 0;
        $alt = true;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return (string) ((10 - ($sum % 10)) % 10);
    }
}
