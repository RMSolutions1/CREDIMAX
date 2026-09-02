<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\LoanService;
use App\Services\WalletService;

final class AdminController
{
    public function index(): void
    {
        require_admin();
        $db = App::db();
        $stats = [
            'users' => (int) ($db->fetch('SELECT COUNT(*) c FROM users')['c'] ?? 0),
            'loans_active' => (int) ($db->fetch("SELECT COUNT(*) c FROM loans WHERE status = 'active'")['c'] ?? 0),
            'loans_open' => (int) ($db->fetch("SELECT COUNT(*) c FROM loans WHERE status IN ('open','funding')")['c'] ?? 0),
            'volume' => (float) ($db->fetch("SELECT COALESCE(SUM(principal),0) s FROM loans WHERE status IN ('active','completed','funded')")['s'] ?? 0),
            'wallet_total' => (float) ($db->fetch('SELECT COALESCE(SUM(balance),0) s FROM wallets')['s'] ?? 0),
            'kyc_pending' => (int) ($db->fetch("SELECT COUNT(*) c FROM users WHERE kyc_status = 'submitted'")['c'] ?? 0),
        ];
        View::render('admin/index', ['title' => 'Administración', 'stats' => $stats]);
    }

    public function users(): void
    {
        require_permission('support.view_users');
        $users = App::db()->fetchAll('SELECT u.*, w.balance FROM users u LEFT JOIN wallets w ON w.user_id = u.id ORDER BY u.id DESC LIMIT 200');
        View::render('admin/users', ['title' => 'Usuarios', 'users' => $users]);
    }

    public function toggleUser(string $id): void
    {
        require_permission('support.view_users');
        Csrf::requireValid();
        $uid = (int) $id;
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$user || (int) $user['id'] === auth_id()) {
            Session::flash('error', 'Operación no permitida.');
            redirect(url('/admin/users'));
        }
        $new = $user['status'] === 'active' ? 'suspended' : 'active';
        App::db()->update('users', ['status' => $new], 'id = ?', [$uid]);
        audit_log('admin.user_status', 'user', (string) $uid, ['status' => $new]);
        Session::flash('success', 'Estado de usuario actualizado.');
        redirect(url('/admin/users'));
    }

    public function kyc(): void
    {
        require_permission('kyc.view');
        $db = App::db();
        $users = $db->fetchAll(
            "SELECT * FROM users WHERE kyc_status IN ('submitted','pending','approved','rejected') ORDER BY FIELD(kyc_status,'submitted','pending','rejected','approved'), id DESC LIMIT 200"
        );
        $docsByUser = [];
        if ($users) {
            $ids = array_map(static fn($u) => (int) $u['id'], $users);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $docs = $db->fetchAll(
                "SELECT id, user_id, doc_type, file_path, status, created_at, encryption_scheme FROM kyc_documents WHERE user_id IN ($placeholders) ORDER BY id DESC",
                $ids
            );
            foreach ($docs as $doc) {
                $docsByUser[(int) $doc['user_id']][] = $doc;
            }
        }
        View::render('admin/kyc', ['title' => 'KYC', 'users' => $users, 'docsByUser' => $docsByUser]);
    }

    public function kycDocument(string $userId, string $docId): void
    {
        require_permission('kyc.view_doc');
        $uid = (int) $userId;
        $did = (int) $docId;
        $doc = App::db()->fetch(
            'SELECT * FROM kyc_documents WHERE id = ? AND user_id = ?',
            [$did, $uid]
        );
        if (!$doc) {
            http_response_code(404);
            echo 'Documento no encontrado.';
            return;
        }
        $rel = str_replace('\\', '/', (string) $doc['file_path']);
        $expectedPrefix = 'storage/uploads/kyc/' . $uid . '/';
        if (!str_starts_with($rel, $expectedPrefix) || str_contains($rel, '..')) {
            http_response_code(403);
            echo 'Ruta inválida.';
            return;
        }
        try {
            $contents = kyc_decrypt_file_contents($doc);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Documento no disponible: ' . $e->getMessage();
            return;
        }
        $mime = 'application/octet-stream';
        $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
        if ($finfo !== false) {
            $tmp = @tempnam(sys_get_temp_dir(), 'kyc');
            if ($tmp !== false) {
                @file_put_contents($tmp, $contents);
                $detected = @finfo_file($finfo, $tmp);
                @unlink($tmp);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
            @finfo_close($finfo);
        }
        audit_log('admin.kyc_view_doc', 'kyc_document', (string) $did, ['user_id' => $uid, 'encryption_scheme' => (string) ($doc['encryption_scheme'] ?? 'plain')]);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Disposition: inline; filename="' . basename((string) ($doc['file_name'] ?? ('doc-' . $did))) . '"');
        header('Content-Length: ' . (string) strlen($contents));
        echo $contents;
    }

    public function kycReview(string $id): void
    {
        require_permission('kyc.review');
        Csrf::requireValid();
        $uid = (int) $id;
        $decision = $_POST['decision'] ?? '';
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            Session::flash('error', 'Decisión inválida.');
            redirect(url('/admin/kyc'));
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        App::db()->update('users', [
            'kyc_status' => $decision,
            'kyc_notes' => $notes !== '' ? $notes : null,
        ], 'id = ?', [$uid]);
        App::db()->query(
            "UPDATE kyc_documents SET status = ?, reviewer_id = ?, reviewed_at = NOW() WHERE user_id = ? AND status = 'pending'",
            [$decision, auth_id(), $uid]
        );
        notify($uid, 'Verificación KYC', $decision === 'approved' ? 'Tu identidad fue aprobada. Ya podés operar créditos.' : 'Tu verificación fue rechazada. Revisá los datos y volvé a enviar.', url('/kyc'));
        audit_log('admin.kyc', 'user', (string) $uid, ['decision' => $decision]);
        Session::flash('success', 'KYC actualizado.');
        redirect(url('/admin/kyc'));
    }

    public function loans(): void
    {
        require_permission('support.view_loans');
        $loans = App::db()->fetchAll(
            'SELECT l.*, u.email, u.credimax_id FROM loans l JOIN users u ON u.id = l.borrower_id ORDER BY l.id DESC LIMIT 200'
        );
        View::render('admin/loans', ['title' => 'Créditos', 'loans' => $loans]);
    }

    public function products(): void
    {
        require_admin();
        $products = App::db()->fetchAll('SELECT * FROM loan_products ORDER BY id');
        View::render('admin/products', ['title' => 'Productos', 'products' => $products]);
    }

    public function saveProduct(): void
    {
        require_admin();
        Csrf::requireValid();
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'min_amount' => (float) ($_POST['min_amount'] ?? 0),
            'max_amount' => (float) ($_POST['max_amount'] ?? 0),
            'min_term_months' => (int) ($_POST['min_term_months'] ?? 1),
            'max_term_months' => (int) ($_POST['max_term_months'] ?? 12),
            'annual_rate' => (float) ($_POST['annual_rate'] ?? 0),
            'origination_fee_pct' => (float) ($_POST['origination_fee_pct'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id > 0) {
            App::db()->update('loan_products', $data, 'id = ?', [$id]);
        } else {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['name']) ?? 'producto');
            $data['slug'] = trim($slug, '-') . '-' . bin2hex(random_bytes(2));
            $data['description'] = '';
            $data['late_fee_pct'] = 5;
            $data['is_p2p'] = 1;
            App::db()->insert('loan_products', $data);
        }
        Session::flash('success', 'Producto guardado.');
        redirect(url('/admin/products'));
    }

    public function adjustWallet(): void
    {
        require_permission('treasury.adjust_wallet');
        Csrf::requireValid();
        try {
            $target = trim((string) ($_POST['credimax_id'] ?? ''));
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            $direction = ($_POST['direction'] ?? 'credit') === 'debit' ? 'debit' : 'credit';
            $fundType = in_array($_POST['fund_type'] ?? '', ['customer', 'own', 'aum_adjust'], true)
                ? $_POST['fund_type']
                : 'customer';
            $reason = trim((string) ($_POST['reason'] ?? 'Ajuste administrativo'));
            $user = App::db()->fetch('SELECT id FROM users WHERE credimax_id = ? OR email = ?', [$target, strtolower($target)]);
            if (!$user) {
                throw new \RuntimeException('Usuario no encontrado (Credimax ID o email).');
            }
            otp_redirect_if_needed('admin:wallet-adjust:' . (int) $user['id'] . ':' . $amount, abs($amount), url('/admin/funds'), [
                'credimax_id' => $target,
                'direction' => $direction,
                'fund_type' => $fundType,
                'reason' => $reason,
            ]);
            (new \App\Services\TreasuryService())->adminAdjustBalance(
                auth_id(),
                (int) $user['id'],
                $amount,
                $direction,
                $reason,
                $fundType
            );
            Session::flash('success', 'Saldo ' . ($direction === 'credit' ? 'acreditado' : 'debitado') . ' correctamente.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/funds'));
    }

    public function funds(): void
    {
        require_permission('treasury.view');
        $treasury = (new \App\Services\TreasuryService())->snapshot();
        $pending = App::db()->fetchAll(
            "SELECT d.*, u.credimax_id, u.email, u.first_name, u.last_name
             FROM fund_deposits d JOIN users u ON u.id = d.user_id
             WHERE d.status = 'pending' ORDER BY d.id ASC LIMIT 100"
        );
        $pendingWithdrawals = App::db()->fetchAll(
            "SELECT w.*, u.credimax_id, u.email, u.first_name, u.last_name
             FROM withdraw_requests w JOIN users u ON u.id = w.user_id
             WHERE w.status = 'pending' ORDER BY w.id ASC LIMIT 100"
        );
        $ledger = App::db()->fetchAll(
            'SELECT l.*, u.credimax_id FROM admin_ledger l JOIN users u ON u.id = l.target_user_id ORDER BY l.id DESC LIMIT 50'
        );
        $mandates = App::db()->fetchAll(
            "SELECT m.*, u.credimax_id, u.email, w.available_balance
             FROM lender_mandates m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE m.mode = 'auto' ORDER BY m.updated_at DESC LIMIT 50"
        );
        View::render('admin/funds', [
            'title' => 'Fondos y tesorería',
            'treasury' => $treasury,
            'pending' => $pending,
            'pendingWithdrawals' => $pendingWithdrawals,
            'ledger' => $ledger,
            'mandates' => $mandates,
        ]);
    }

    public function confirmDeposit(string $id): void
    {
        require_permission('treasury.deposit_confirm');
        Csrf::requireValid();
        try {
            $action = $_POST['action'] ?? 'confirm';
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $svc = new \App\Services\TreasuryService();
            if ($action === 'reject') {
                $svc->rejectDeposit((int) $id, auth_id(), $notes);
                Session::flash('success', 'Depósito rechazado.');
            } else {
                $svc->confirmDeposit((int) $id, auth_id(), $notes);
                Session::flash('success', 'Depósito confirmado y acreditado.');
            }
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/funds'));
    }

    public function confirmWithdraw(string $id): void
    {
        $perm = ($_POST['action'] ?? 'paid') === 'reject' ? 'treasury.withdraw_reject' : 'treasury.withdraw_confirm';
        require_permission($perm);
        Csrf::requireValid();
        try {
            $action = $_POST['action'] ?? 'paid';
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $svc = new \App\Services\TreasuryService();
            $req = App::db()->fetch('SELECT * FROM withdraw_requests WHERE id = ?', [(int) $id]);
            if ($req && $action !== 'reject') {
                $amount = (float) ($req['amount'] ?? 0.0);
                otp_redirect_if_needed('admin:confirm_withdraw:' . (int) $id, $amount, url('/admin/funds'));
            }
            if ($action === 'reject') {
                $svc->rejectWithdraw((int) $id, auth_id(), $notes);
                Session::flash('success', 'Retiro rechazado y saldo reintegrado.');
            } else {
                $svc->markWithdrawPaid((int) $id, auth_id(), $notes);
                Session::flash('success', 'Retiro marcado como pagado (transferencia bancaria ejecutada).');
            }
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/funds'));
    }

    public function injectOwn(): void
    {
        require_permission('treasury.inject_own');
        Csrf::requireValid();
        try {
            $raw = trim((string) ($_POST['amount'] ?? '0'));
            $amount = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            $reason = trim((string) ($_POST['reason'] ?? 'Aporte capital propio'));
            otp_redirect_if_needed('admin:inject-own:' . $amount, $amount, url('/admin/funds'), [
                'reason' => $reason,
            ]);
            (new \App\Services\TreasuryService())->injectOwnCapital(auth_id(), $amount, $reason);
            (new WalletService())->deposit(auth_id(), $amount, 'Capital propio Credimax');
            Session::flash('success', 'Capital propio registrado e inyectado a wallet admin.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/funds'));
    }

    public function recalcAum(): void
    {
        require_permission('treasury.recalc_aum');
        Csrf::requireValid();
        try {
            $r = (new \App\Services\TreasuryService())->recalcAum(auth_id());
            Session::flash('success', sprintf(
                'AUM recalculado: %s → %s (ajuste %s).',
                money($r['before']),
                money($r['after']),
                money($r['delta'])
            ));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/admin/funds'));
    }

    public function runAutoInvest(string $id): void
    {
        require_admin();
        Csrf::requireValid();
        try {
            $r = (new \App\Services\AutoInvestService())->allocateForLoan((int) $id);
            $n = count($r['actions'] ?? []);
            Session::flash('success', "Alertas enviadas a inversores compatibles: {$n}. El fondeo sigue siendo manual (BCRA PSCPP).");
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/loans/' . (int) $id));
    }

    public function runOverdue(): void
    {
        require_permission('treasury.run_overdue');
        Csrf::requireValid();
        $n = (new LoanService())->markOverdue();
        $f = 0;
        try {
            $f = (new LoanService())->applyLateFees();
        } catch (\Throwable $e) {
            Session::flash('error', 'Mora aplicada pero intereses punitorios fallaron: ' . $e->getMessage());
        }
        Session::flash('success', "Cuotas marcadas en mora: {$n}. Intereses punitorios aplicados: {$f}.");
        redirect(url('/admin'));
    }

    public function exportAuditCsv(): void
    {
        require_permission('audit.export');
        $db = App::db();
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $sql = 'SELECT a.id, a.created_at, a.user_id, u.email, u.credimax_id, a.action, a.entity_type, a.entity_id, a.ip_address, a.meta FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id';
        $where = [];
        $args = [];
        if ($from !== '') {
            $where[] = 'DATE(a.created_at) >= ?';
            $args[] = $from;
        }
        if ($to !== '') {
            $where[] = 'DATE(a.created_at) <= ?';
            $args[] = $to;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.id DESC LIMIT 20000';
        $rows = $db->fetchAll($sql, $args);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['id'], $r['created_at'], $r['user_id'], (string) ($r['email'] ?? ''), (string) ($r['credimax_id'] ?? ''),
                $r['action'], $r['entity_type'], $r['entity_id'], (string) ($r['ip_address'] ?? ''), (string) ($r['meta'] ?? ''),
            ];
        }
        csv_emit('audit-credimax-' . date('Ymd') . '.csv', $out, [
            'ID','Fecha','UserID','Email','CredimaxID','Acción','Entidad','Entidad ID','IP','Meta JSON',
        ]);
    }

    public function exportAfipRentas(): void
    {
        require_permission('audit.export');
        $year = (int) ($_GET['year'] ?? (int) date('Y'));
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT u.id, u.email, u.credimax_id, u.document_type, u.document_number,
                    u.first_name, u.last_name, u.tax_status, u.tax_id,
                    ROUND(COALESCE(SUM(CASE WHEN wt.type='loan_repay' THEN wt.amount ELSE 0 END),2),2) AS intereses_brutos,
                    ROUND(COALESCE(SUM(CASE WHEN wt.type='loan_repay' THEN wt.amount*0.035 ELSE 0 END),2),2) AS retencion_iva_sugerida,
                    ROUND(COALESCE(SUM(CASE WHEN wt.type='loan_repay' THEN wt.amount*0.06 ELSE 0 END),2),2) AS retencion_ganancias_sugerida
             FROM users u
             JOIN wallet_transactions wt ON wt.user_id = u.id
             WHERE wt.type = 'loan_repay' AND YEAR(wt.created_at) = ?
             GROUP BY u.id
             HAVING intereses_brutos > 0
             ORDER BY u.last_name, u.first_name",
            [$year]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $year, (string) ($r['document_type'] ?? 'DNI'), (string) ($r['document_number'] ?? ''),
                (string) ($r['first_name'] ?? ''), (string) ($r['last_name'] ?? ''),
                (string) ($r['tax_id'] ?? ''), (string) ($r['tax_status'] ?? ''),
                (string) ($r['email'] ?? ''), (string) ($r['credimax_id'] ?? ''),
                number_format((float) $r['intereses_brutos'], 2, ',', ''),
                number_format((float) $r['retencion_iva_sugerida'], 2, ',', ''),
                number_format((float) $r['retencion_ganancias_sugerida'], 2, ',', ''),
            ];
        }
        csv_emit('afip-rentas-inversores-' . $year . '.csv', $out, [
            'Ejercicio','TipoDoc','NroDoc','Nombre','Apellido','CUIT/CUIL','CondicionIVA','Email','CredimaxID',
            'InteresesBrutosARS','RetencionIVASugerida','RetencionGananciasSugerida',
        ]);
    }

    public function exportUsersCsv(): void
    {
        require_permission('support.view_users');
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT u.id, u.credimax_id, u.first_name, u.last_name, u.email, u.phone, u.dni, u.cuit,
                    u.role, u.account_type, u.kyc_status, u.status, u.can_lend, u.can_borrow,
                    u.email_verified_at, u.last_login_at, u.created_at,
                    COALESCE(w.balance,0) AS balance
             FROM users u LEFT JOIN wallets w ON w.user_id = u.id
             ORDER BY u.id DESC'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['id'], $r['credimax_id'], $r['first_name'], $r['last_name'], $r['email'],
                (string) ($r['phone'] ?? ''), (string) ($r['dni'] ?? ''), (string) ($r['cuit'] ?? ''),
                $r['role'], $r['account_type'], $r['kyc_status'], $r['status'],
                $r['can_lend'] ? 'Sí' : 'No', $r['can_borrow'] ? 'Sí' : 'No',
                (string) ($r['email_verified_at'] ?? ''), (string) ($r['last_login_at'] ?? ''),
                (string) ($r['created_at'] ?? ''),
                number_format((float) $r['balance'], 2, ',', ''),
            ];
        }
        csv_emit('usuarios-credimax-' . date('Ymd') . '.csv', $out, [
            'ID','CredimaxID','Nombre','Apellido','Email','Teléfono','DNI','CUIT',
            'Rol','TipoCuenta','KYC','Estado','PuedeInvertir','PuedePedir',
            'EmailVerificado','ÚltimoLogin','Creado','SaldoBilleteraARS',
        ]);
    }

    public function exportFundsCsv(): void
    {
        require_permission('treasury.view');
        $db = App::db();
        $deposits = $db->fetchAll(
            'SELECT d.id, d.created_at, u.credimax_id, u.email, d.amount, d.method, d.reference, d.status, d.notes
             FROM fund_deposits d LEFT JOIN users u ON u.id = d.user_id
             ORDER BY d.id DESC LIMIT 5000'
        );
        $withdraws = $db->fetchAll(
            'SELECT w.id, w.created_at, u.credimax_id, u.email, w.amount, w.destination_type, w.destination_value,
                    w.beneficiary_name, w.status, w.admin_notes
             FROM withdraw_requests w LEFT JOIN users u ON u.id = w.user_id
             ORDER BY w.id DESC LIMIT 5000'
        );
        $out = [];
        $out[] = ['=== DEPÓSITOS ===','','','','','','','','','','',''];
        $out[] = ['Tipo','ID','Fecha','CredimaxID','Email','Monto','Método','Referencia','Estado','Notas','Destino','Titular'];
        foreach ($deposits as $r) {
            $out[] = ['DEPOSITO', $r['id'], $r['created_at'], $r['credimax_id'], (string) ($r['email'] ?? ''),
                number_format((float) $r['amount'], 2, ',', ''), (string) ($r['method'] ?? ''),
                (string) ($r['reference'] ?? ''), (string) ($r['status'] ?? ''), (string) ($r['notes'] ?? ''), '', ''];
        }
        $out[] = ['','','','','','','','','','','',''];
        $out[] = ['=== RETIROS ===','','','','','','','','','','',''];
        $out[] = ['Tipo','ID','Fecha','CredimaxID','Email','Monto','Método','Referencia','Estado','Notas','Destino','Titular'];
        foreach ($withdraws as $r) {
            $out[] = ['RETIRO', $r['id'], $r['created_at'], $r['credimax_id'], (string) ($r['email'] ?? ''),
                number_format((float) $r['amount'], 2, ',', ''), (string) ($r['destination_type'] ?? ''),
                (string) ($r['destination_value'] ?? ''), (string) ($r['status'] ?? ''),
                (string) ($r['admin_notes'] ?? ''), (string) ($r['destination_value'] ?? ''),
                (string) ($r['beneficiary_name'] ?? '')];
        }
        csv_emit('fondos-tesoreria-' . date('Ymd') . '.csv', $out, [
            'Sección / Tipo','ID','Fecha','CredimaxID','Email','MontoARS','Método/TipoDestino','Referencia/Destino',
            'Estado','Notas','DestinoExtra','Titular',
        ]);
    }
}
