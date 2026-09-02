<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * Motor bancario privado Credimax.
 * Cuentas, titularidad CVU/alias, transferencias, DEBIN y ECHEQ — 100% interno.
 */
final class BankingService
{
    public function __construct(
        private ?WalletService $wallet = null,
        private ?CvuService $cvu = null,
    ) {
        $this->wallet = $wallet ?? new WalletService();
        $this->cvu = $cvu ?? new CvuService();
    }

    public function bankId(): string
    {
        return (string) (App::db()->fetch("SELECT setting_value FROM settings WHERE setting_key = 'bank_id'")['setting_value'] ?? '900');
    }

    public function ensureAccount(int $userId): array
    {
        $w = $this->wallet->ensureWallet($userId);
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        return $this->cvu->ensureBankIdentity($userId, $w, $user['dni'] ?? null);
    }

    public function listAccounts(int $userId): array
    {
        $w = $this->ensureAccount($userId);
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        return [$this->formatAccount($w, $user)];
    }

    public function formatAccount(array $w, ?array $user = null): array
    {
        if (!$user) {
            $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [(int) $w['user_id']]);
        }
        return [
            'id' => $w['account_code'],
            'label' => $w['alias'] ?? ('Cuenta ' . ($user['credimax_id'] ?? '')),
            'number' => $w['cvu'],
            'type' => $w['account_type'] === 'CC' ? 'Cuenta Corriente' : 'Caja de Ahorro',
            'status' => $w['status'] === 'active' ? 'NORMAL' : strtoupper((string) $w['status']),
            'owners' => [[
                'id' => $w['cuit'] ?: ($user['dni'] ?? $user['credimax_id']),
                'display_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'id_type' => $w['cuit'] ? 'CUIT' : 'CREDIMAX',
                'is_physical_person' => true,
            ]],
            'balance' => [
                'currency' => $w['currency'] ?? 'ARS',
                'amount' => (float) $w['available_balance'],
            ],
            'bank_id' => (string) ($w['bank_id'] ?? $this->bankId()),
            'account_routing' => [
                'scheme' => 'CVU',
                'address' => $w['cvu'],
            ],
            'extras' => [
                'reserved_balance' => (float) $w['reserved_balance'],
                'total_balance' => (float) $w['balance'],
                'alias' => $w['alias'],
                'credimax_id' => $user['credimax_id'] ?? null,
                'qr_token' => $w['qr_token'],
            ],
        ];
    }

    public function getOwnership(?string $cvu = null, ?string $alias = null): array
    {
        $row = $this->cvu->findByCvuOrAlias($cvu, $alias);
        if (!$row || ($row['user_status'] ?? '') !== 'active' || ($row['status'] ?? '') !== 'active') {
            throw new RuntimeException('Cuenta no encontrada o inactiva.', 404);
        }
        return [
            'owners' => [[
                'id' => $row['cuit'] ?: ($row['dni'] ?? $row['credimax_id']),
                'display_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'id_type' => $row['cuit'] ? 'CUIT' : 'CREDIMAX',
                'is_physical_person' => true,
            ]],
            'type' => $row['account_type'] ?? 'CA',
            'is_active' => true,
            'currency' => $row['currency'] ?? 'ARS',
            'label' => $row['alias'],
            'account_routing' => [
                'scheme' => 'CVU',
                'address' => $row['cvu'],
            ],
            'bank_routing' => [
                'scheme' => 'CREDIBANK',
                'address' => 'Credimax Bank Privado',
                'code' => (string) ($row['bank_id'] ?? '900'),
            ],
        ];
    }

    public function getMovements(int $userId, string $accountId, array $filters = []): array
    {
        $w = $this->resolveOwnedAccount($userId, $accountId);
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 25)));
        $offset = max(0, ((int) ($filters['offset'] ?? 1) - 1) * $limit);
        $params = [(int) $w['user_id']];
        $where = 'user_id = ?';
        if (!empty($filters['from'])) {
            $where .= ' AND created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where .= ' AND created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        $sql = "SELECT * FROM wallet_transactions WHERE $where ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
        $rows = App::db()->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $t) {
            $creditTypes = ['deposit', 'transfer_in', 'loan_disburse', 'refund', 'adjustment'];
            $isCredit = in_array($t['type'], $creditTypes, true) || ($t['type'] === 'loan_repay' && str_starts_with((string) $t['description'], 'Cobro'));
            $amount = (float) $t['amount'];
            $out[] = [
                'id' => $t['reference'],
                'counterparty' => [
                    'id' => $t['related_user_id'] ? (string) $t['related_user_id'] : null,
                    'name' => null,
                    'account_routing' => null,
                ],
                'details' => [
                    'type' => strtoupper((string) $t['type']),
                    'description' => $t['description'],
                    'posted' => date('c', strtotime((string) $t['created_at'])),
                    'completed' => date('c', strtotime((string) $t['created_at'])),
                    'value' => [
                        'currency' => 'ARS',
                        'amount' => $isCredit ? $amount : -$amount,
                    ],
                    'motive' => $t['type'],
                    'reference_number' => $t['reference'],
                    'new_balance' => [
                        'currency' => 'ARS',
                        'amount' => (float) $t['balance_after'],
                    ],
                ],
            ];
        }
        return $out;
    }

    public function transfer(int $userId, string $accountId, array $req): array
    {
        $originId = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($req['origin_id'] ?? '')) ?: '', 0, 15);
        if ($originId === '') {
            throw new RuntimeException('origin_id es obligatorio (máx. 15).', 422);
        }

        $existing = App::db()->fetch(
            'SELECT * FROM bank_transfers WHERE from_user_id = ? AND origin_id = ?',
            [$userId, $originId]
        );
        if ($existing) {
            return $this->formatTransfer($existing);
        }

        $amount = (float) ($req['value']['amount'] ?? 0);
        $currency = (string) ($req['value']['currency'] ?? 'ARS');
        if ($amount <= 0) {
            throw new RuntimeException('Monto inválido.', 422);
        }
        if ($currency !== 'ARS') {
            throw new RuntimeException('Solo ARS soportado en red privada Credimax.', 422);
        }

        $toCbu = isset($req['to']['cbu']) ? preg_replace('/\D+/', '', (string) $req['to']['cbu']) : null;
        $toAlias = isset($req['to']['label']) ? strtolower(trim((string) $req['to']['label'])) : null;
        if (!$toCbu && !$toAlias) {
            throw new RuntimeException('to.cbu o to.label es obligatorio.', 422);
        }

        $from = $this->resolveOwnedAccount($userId, $accountId);
        $dest = $this->cvu->findByCvuOrAlias($toCbu, $toAlias);
        if (!$dest) {
            throw new RuntimeException('Destinatario inexistente en red Credimax.', 404);
        }
        if ((int) $dest['user_id'] === $userId) {
            throw new RuntimeException('No podés transferirte a tu misma cuenta.', 422);
        }

        $db = App::db();
        $db->beginTransaction();
        try {
            $transferId = 'TR-' . strtoupper(bin2hex(random_bytes(6)));
            $id = $db->insert('bank_transfers', [
                'transfer_id' => $transferId,
                'origin_id' => $originId,
                'from_user_id' => $userId,
                'from_wallet_id' => (int) $from['id'],
                'to_user_id' => (int) $dest['user_id'],
                'to_wallet_id' => (int) $dest['id'],
                'to_cvu' => $dest['cvu'],
                'to_alias' => $dest['alias'],
                'to_cuit' => $req['to']['cuit'] ?? $dest['cuit'],
                'amount' => $amount,
                'currency' => 'ARS',
                'concept' => substr((string) ($req['concept'] ?? 'VAR'), 0, 10),
                'description' => substr((string) ($req['description'] ?? 'Transferencia Credimax'), 0, 100),
                'status' => 'PENDING',
                'status_description' => 'PROCESSING',
            ]);

            $note = (string) ($req['description'] ?? 'Transferencia CVU Credimax');
            $result = $this->wallet->transfer($userId, (int) $dest['user_id'], $amount, $note);

            $db->update('bank_transfers', [
                'status' => 'COMPLETED',
                'status_description' => 'COMPLETED',
                'wallet_tx_out' => $result['out_reference'] ?? null,
                'wallet_tx_in' => $result['in_reference'] ?? null,
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);

            $this->emit('transfer.completed', 'bank_transfer', $transferId, $userId, [
                'amount' => $amount,
                'to' => $dest['cvu'],
            ]);

            $row = $db->fetch('SELECT * FROM bank_transfers WHERE id = ?', [$id]);
            $db->commit();
            return $this->formatTransfer($row);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function createDebin(int $sellerId, string $accountId, array $req): array
    {
        $originId = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($req['origin_id'] ?? '')) ?: '', 0, 15);
        if ($originId === '') {
            throw new RuntimeException('origin_id obligatorio.', 422);
        }
        $existing = App::db()->fetch(
            'SELECT * FROM debins WHERE seller_user_id = ? AND origin_id = ?',
            [$sellerId, $originId]
        );
        if ($existing) {
            return $this->formatDebin($existing);
        }

        $amount = (float) ($req['value']['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Monto inválido.', 422);
        }
        $expiration = min(4320, max(1, (int) ($req['expiration'] ?? 60)));
        $toCbu = isset($req['to']['cbu']) ? preg_replace('/\D+/', '', (string) $req['to']['cbu']) : null;
        $toAlias = isset($req['to']['label']) ? strtolower(trim((string) $req['to']['label'])) : null;
        $buyer = $this->cvu->findByCvuOrAlias($toCbu, $toAlias);
        if (!$buyer) {
            throw new RuntimeException('Comprador no encontrado en red Credimax.', 404);
        }

        $seller = $this->resolveOwnedAccount($sellerId, $accountId);
        $debinId = 'DB-' . strtoupper(bin2hex(random_bytes(6)));
        $id = App::db()->insert('debins', [
            'debin_id' => $debinId,
            'origin_id' => $originId,
            'seller_user_id' => $sellerId,
            'seller_wallet_id' => (int) $seller['id'],
            'buyer_user_id' => (int) $buyer['user_id'],
            'buyer_wallet_id' => (int) $buyer['id'],
            'buyer_cvu' => $buyer['cvu'],
            'buyer_alias' => $buyer['alias'],
            'amount' => $amount,
            'currency' => 'ARS',
            'concept' => substr((string) ($req['concept'] ?? 'VAR'), 0, 10),
            'description' => substr((string) ($req['description'] ?? 'DEBIN Credimax'), 0, 100),
            'provision' => $req['provision'] ?? null,
            'expiration_minutes' => $expiration,
            'expires_at' => date('Y-m-d H:i:s', time() + $expiration * 60),
            'status' => 'AWAITING_CONFIRMATION',
            'status_description' => 'AWAITING_CONFIRMATION',
        ]);

        notify((int) $buyer['user_id'], 'DEBIN pendiente', 'Te solicitaron ' . money($amount) . ' vía DEBIN Credimax.', url('/banking/debin'));
        $row = App::db()->fetch('SELECT * FROM debins WHERE id = ?', [$id]);
        $this->emit('debin.created', 'debin', $debinId, $sellerId, ['amount' => $amount]);
        return $this->formatDebin($row);
    }

    public function resolveDebin(int $buyerId, string $debinId, string $decision): array
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $debin = $db->fetch('SELECT * FROM debins WHERE debin_id = ? FOR UPDATE', [$debinId]);
            if (!$debin || (int) $debin['buyer_user_id'] !== $buyerId) {
                throw new RuntimeException('DEBIN no encontrado.', 404);
            }
            if ($debin['status'] !== 'AWAITING_CONFIRMATION') {
                throw new RuntimeException('DEBIN no está pendiente.', 409);
            }
            if (strtotime((string) $debin['expires_at']) < time()) {
                $db->update('debins', [
                    'status' => 'EXPIRED',
                    'status_description' => 'EXPIRED',
                    'resolved_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [(int) $debin['id']]);
                $db->commit();
                throw new RuntimeException('DEBIN expirado.', 410);
            }

            if ($decision === 'reject') {
                $db->update('debins', [
                    'status' => 'REJECTED',
                    'status_description' => 'REJECTED_BY_BUYER',
                    'resolved_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [(int) $debin['id']]);
                notify((int) $debin['seller_user_id'], 'DEBIN rechazado', 'El comprador rechazó el DEBIN ' . $debinId, url('/banking/debin'));
                $row = $db->fetch('SELECT * FROM debins WHERE id = ?', [(int) $debin['id']]);
                $db->commit();
                return $this->formatDebin($row);
            }

            // approve: debit buyer, credit seller
            $this->wallet->debit($buyerId, (float) $debin['amount'], 'transfer_out', 'DEBIN ' . $debinId . ' aprobado');
            $this->wallet->credit((int) $debin['seller_user_id'], (float) $debin['amount'], 'transfer_in', 'DEBIN ' . $debinId . ' cobrado', null, $buyerId);

            $db->update('debins', [
                'status' => 'COMPLETED',
                'status_description' => 'COMPLETED',
                'resolved_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $debin['id']]);

            notify((int) $debin['seller_user_id'], 'DEBIN cobrado', 'Se acreditaron ' . money((float) $debin['amount']), url('/wallet'));
            $row = $db->fetch('SELECT * FROM debins WHERE id = ?', [(int) $debin['id']]);
            $this->emit('debin.completed', 'debin', $debinId, $buyerId, []);
            $db->commit();
            return $this->formatDebin($row);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function issueEcheq(int $issuerId, string $accountId, array $data): array
    {
        $issuer = $this->resolveOwnedAccount($issuerId, $accountId);
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Monto inválido.', 422);
        }
        $paymentDate = (string) ($data['payment_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $receiverCuit = preg_replace('/\D+/', '', (string) ($data['receiver_cuit'] ?? ''));
        $receiverName = trim((string) ($data['receiver_name'] ?? ''));
        $receiverUserId = null;
        if (!empty($data['receiver_cvu']) || !empty($data['receiver_alias'])) {
            $recv = $this->cvu->findByCvuOrAlias(
                isset($data['receiver_cvu']) ? (string) $data['receiver_cvu'] : null,
                isset($data['receiver_alias']) ? (string) $data['receiver_alias'] : null
            );
            if ($recv) {
                $receiverUserId = (int) $recv['user_id'];
                $receiverName = $receiverName ?: trim($recv['first_name'] . ' ' . $recv['last_name']);
                $receiverCuit = $receiverCuit ?: ($recv['cuit'] ?: $recv['dni']);
            }
        }
        if ($receiverName === '') {
            throw new RuntimeException('Beneficiario requerido.', 422);
        }

        // Reservar fondos del emisor hasta el cobro/depósito
        $db = App::db();
        $db->beginTransaction();
        try {
            $this->wallet->reserve($issuerId, $amount);
            $echeqId = 'EQ-' . strtoupper(bin2hex(random_bytes(6)));
            $id = $db->insert('echeqs', [
                'echeq_id' => $echeqId,
                'issuer_user_id' => $issuerId,
                'issuer_wallet_id' => (int) $issuer['id'],
                'receiver_user_id' => $receiverUserId,
                'receiver_cuit' => $receiverCuit ?: null,
                'receiver_name' => $receiverName,
                'amount' => $amount,
                'currency' => 'ARS',
                'check_type' => ($data['check_type'] ?? 'CPD') === 'CC' ? 'CC' : 'CPD',
                'payment_date' => $paymentDate,
                'status' => 'ACTIVE',
                'current_holder_user_id' => $receiverUserId ?: $issuerId,
                'description' => substr((string) ($data['description'] ?? 'ECHEQ Credimax'), 0, 160),
            ]);
            $db->insert('echeq_actions', [
                'echeq_id' => $id,
                'actor_user_id' => $issuerId,
                'action' => 'ISSUE',
                'note' => 'Emisión',
            ]);
            if ($receiverUserId) {
                notify($receiverUserId, 'ECHEQ recibido', 'Recibiste un ECHEQ por ' . money($amount), url('/banking/echeq'));
            }
            $row = $db->fetch('SELECT * FROM echeqs WHERE id = ?', [$id]);
            $db->commit();
            return $this->formatEcheq($row);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function listEcheqs(int $userId, string $accountId, array $filters): array
    {
        $this->resolveOwnedAccount($userId, $accountId);
        $status = strtoupper((string) ($filters['status'] ?? 'ACTIVE'));
        $mode = strtoupper((string) ($filters['mode'] ?? 'ISSUER'));
        $params = [$status];
        if ($mode === 'RECEIVER') {
            $sql = 'SELECT * FROM echeqs WHERE status = ? AND (receiver_user_id = ? OR current_holder_user_id = ?) ORDER BY id DESC LIMIT 50';
            $params[] = $userId;
            $params[] = $userId;
        } else {
            $sql = 'SELECT * FROM echeqs WHERE status = ? AND issuer_user_id = ? ORDER BY id DESC LIMIT 50';
            $params[] = $userId;
        }
        $rows = App::db()->fetchAll($sql, $params);
        return array_map(fn($r) => $this->formatEcheq($r), $rows);
    }

    public function echeqAction(int $userId, string $echeqId, string $action): array
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $echeq = $db->fetch('SELECT * FROM echeqs WHERE echeq_id = ? FOR UPDATE', [$echeqId]);
            if (!$echeq) {
                throw new RuntimeException('ECHEQ no encontrado.', 404);
            }
            $action = strtoupper($action);
            if ($action === 'DEPOSIT' || $action === 'ACCREDIT') {
                if ((int) $echeq['current_holder_user_id'] !== $userId && (int) $echeq['receiver_user_id'] !== $userId) {
                    throw new RuntimeException('No sos el tenedor del ECHEQ.', 403);
                }
                if (!in_array($echeq['status'], ['ACTIVE', 'CUSTODY', 'ENDORSED'], true)) {
                    throw new RuntimeException('Estado no permite depósito.', 409);
                }
                // Liberar reserva del emisor y acreditar al tenedor
                $this->wallet->consumeReserve(
                    (int) $echeq['issuer_user_id'],
                    (float) $echeq['amount'],
                    'transfer_out',
                    'ECHEQ ' . $echeqId . ' depositado'
                );
                $this->wallet->credit($userId, (float) $echeq['amount'], 'transfer_in', 'ECHEQ ' . $echeqId . ' acreditado', null, (int) $echeq['issuer_user_id']);
                $db->update('echeqs', ['status' => 'ACCREDIT'], 'id = ?', [(int) $echeq['id']]);
                $db->insert('echeq_actions', [
                    'echeq_id' => (int) $echeq['id'],
                    'actor_user_id' => $userId,
                    'action' => 'ACCREDIT',
                    'note' => 'Depósito/acreditación',
                ]);
            } elseif ($action === 'CANCEL') {
                if ((int) $echeq['issuer_user_id'] !== $userId) {
                    throw new RuntimeException('Solo el emisor puede cancelar.', 403);
                }
                if ($echeq['status'] !== 'ACTIVE') {
                    throw new RuntimeException('Solo ECHEQ ACTIVE se cancela.', 409);
                }
                $this->wallet->releaseReserve((int) $echeq['issuer_user_id'], (float) $echeq['amount']);
                $db->update('echeqs', ['status' => 'CANCELLED'], 'id = ?', [(int) $echeq['id']]);
                $db->insert('echeq_actions', [
                    'echeq_id' => (int) $echeq['id'],
                    'actor_user_id' => $userId,
                    'action' => 'CANCEL',
                ]);
            } elseif ($action === 'REJECT') {
                if ((int) $echeq['receiver_user_id'] !== $userId && (int) $echeq['current_holder_user_id'] !== $userId) {
                    throw new RuntimeException('No autorizado.', 403);
                }
                $this->wallet->releaseReserve((int) $echeq['issuer_user_id'], (float) $echeq['amount']);
                $db->update('echeqs', ['status' => 'REJECTED'], 'id = ?', [(int) $echeq['id']]);
                $db->insert('echeq_actions', [
                    'echeq_id' => (int) $echeq['id'],
                    'actor_user_id' => $userId,
                    'action' => 'REJECT',
                ]);
            } else {
                throw new RuntimeException('Acción no soportada.', 422);
            }
            $row = $db->fetch('SELECT * FROM echeqs WHERE id = ?', [(int) $echeq['id']]);
            $db->commit();
            return $this->formatEcheq($row);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function resolveOwnedAccount(int $userId, string $accountId): array
    {
        $w = $this->ensureAccount($userId);
        if ($accountId !== 'owner' && $accountId !== (string) $w['account_code'] && $accountId !== (string) $w['cvu']) {
            throw new RuntimeException('account_id no pertenece al usuario autenticado.', 403);
        }
        return $w;
    }

    private function formatTransfer(?array $row): array
    {
        if (!$row) {
            throw new RuntimeException('Transferencia no encontrada.');
        }
        return [
            'id' => $row['transfer_id'],
            'type' => 'TRANSFER',
            'from' => [
                'bank_id' => $this->bankId(),
                'account_id' => (string) $row['from_wallet_id'],
            ],
            'counterparty' => [
                'id_type' => 'CUIT',
                'account_routing' => [
                    'scheme' => 'CVU',
                    'address' => $row['to_cvu'],
                ],
            ],
            'details' => ['origin_id' => $row['origin_id']],
            'transaction_ids' => array_values(array_filter([$row['wallet_tx_out'], $row['wallet_tx_in']])),
            'status' => $row['status'],
            'status_description' => $row['status_description'],
            'start_date' => date('c', strtotime((string) $row['created_at'])),
            'end_date' => $row['completed_at'] ? date('c', strtotime((string) $row['completed_at'])) : null,
            'charge' => [
                'summary' => $row['description'],
                'value' => ['currency' => $row['currency'], 'amount' => (float) $row['amount']],
            ],
        ];
    }

    private function formatDebin(?array $row): array
    {
        return [
            'id' => $row['debin_id'],
            'type' => 'DEBIN',
            'from' => [
                'bank_id' => $this->bankId(),
                'account_id' => (string) $row['seller_wallet_id'],
            ],
            'details' => [
                'origin_id' => $row['origin_id'],
                'buyer' => [
                    'alias' => $row['buyer_alias'],
                    'cbu' => $row['buyer_cvu'],
                    'bank_code' => '900',
                    'bank_description' => 'Credimax Bank Privado',
                ],
            ],
            'status' => $row['status'] === 'AWAITING_CONFIRMATION' ? 'PENDING' : $row['status'],
            'status_description' => $row['status_description'],
            'start_date' => date('c', strtotime((string) $row['created_at'])),
            'end_date' => $row['resolved_at'] ? date('c', strtotime((string) $row['resolved_at'])) : null,
            'charge' => [
                'summary' => $row['description'],
                'value' => ['currency' => $row['currency'], 'amount' => (float) $row['amount']],
            ],
            'extras' => [
                'expires_at' => $row['expires_at'],
                'debin_id' => $row['debin_id'],
            ],
        ];
    }

    private function formatEcheq(array $row): array
    {
        return [
            'id' => $row['echeq_id'],
            'type' => 'CHECK',
            'from' => [
                'bank_id' => $this->bankId(),
                'account_id' => (string) $row['issuer_wallet_id'],
            ],
            'details' => [
                'check' => [
                    'type' => $row['check_type'],
                    'issued_to' => [
                        'document_number' => $row['receiver_cuit'],
                        'name' => $row['receiver_name'],
                        'document_type' => 'CUIT',
                    ],
                    'possible_actions' => [
                        ['action' => 'DEPOSIT'],
                        ['action' => 'REJECT'],
                        ['action' => 'CANCEL'],
                    ],
                    'payment_date' => $row['payment_date'],
                    'amount' => (float) $row['amount'],
                ],
            ],
            'status' => $row['status'],
            'extras' => [
                'description' => $row['description'],
                'current_holder_user_id' => $row['current_holder_user_id'],
            ],
        ];
    }

    private function emit(string $type, string $entityType, string $entityId, ?int $userId, array $payload): void
    {
        try {
            App::db()->insert('bank_events', [
                'event_type' => $type,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_id' => $userId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('bank_events: ' . $e->getMessage());
        }
    }
}
