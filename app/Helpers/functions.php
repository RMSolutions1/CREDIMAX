<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    $base = rtrim((string) App::config('app_url', ''), '/');
    if (str_starts_with($url, '/') && $base !== '') {
        // Permitir paths relativos al host actual si app_url no aplica en local
        if (!preg_match('#^https?://#i', $url)) {
            header('Location: ' . $url);
            exit;
        }
    }
    header('Location: ' . $url);
    exit;
}

function url(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptDir && $scriptDir !== '/' && $scriptDir !== '.') {
        return $scriptDir . ($path === '/' ? '/' : $path);
    }
    return $path === '/' ? '/' : $path;
}

/** True si la petición debe tratarse como HTTPS (URL config, proxy confiable o puerto 443). */
function request_is_https(): bool
{
    $configured = (string) App::config('app_url', '');
    if (str_starts_with($configured, 'https://')) {
        return true;
    }
    // En producción no confiar en headers de cliente: la fuente de verdad es app_url.
    if ((string) App::config('app_env', 'production') === 'production') {
        return false;
    }
    $trusted = App::config('security.trusted_proxies', []);
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trustForwarded = is_array($trusted) && $trusted !== [] && in_array($remote, $trusted, true);
    if ($trustForwarded) {
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded !== '' && explode(',', $forwarded)[0] === 'https') {
            return true;
        }
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
}

/** URL absoluta (http/https) para APIs externas como Mercado Pago. */
function absolute_url(string $path = '/'): string
{
    $base = rtrim((string) App::config('app_url', ''), '/');
    if ($base === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($dir === '.' || $dir === '\\') {
            $dir = '';
        }
        $base = (request_is_https() ? 'https' : 'http') . '://' . $host . $dir;
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Guarda un upload KYC validando tamaño, extensión y MIME real (finfo).
 * @return string Ruta relativa desde la raíz del proyecto
 */
function store_kyc_upload(array $file, string $field, int $userId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir ' . $field);
    }
    if (!extension_loaded('fileinfo') || !function_exists('finfo_open')) {
        throw new RuntimeException('El servidor no puede validar el tipo de archivo (falta fileinfo).');
    }
    $maxBytes = ((int) App::config('uploads.max_mb', 5)) * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Archivo demasiado grande: ' . $field);
    }

    $allowedExt = App::config('uploads.allowed', ['jpg', 'jpeg', 'png', 'pdf', 'webp']);
    if (!is_array($allowedExt)) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Formato no permitido: ' . $ext);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Archivo de subida inválido.');
    }
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('No se pudo iniciar la validación MIME.');
    }
    $detected = (string) finfo_file($finfo, $tmp);
    finfo_close($finfo);
    if ($detected === '' || !isset($mimeMap[$detected])) {
        throw new RuntimeException('El contenido del archivo no coincide con un tipo permitido.');
    }
    if ($mimeMap[$detected] !== $ext && !($ext === 'jpeg' && $mimeMap[$detected] === 'jpg')) {
        $ext = $mimeMap[$detected];
    }

    $dir = CREDIMAX_ROOT . '/storage/uploads/kyc/' . $userId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de KYC.');
    }
    $name = preg_replace('/[^a-z0-9_]/', '', $field) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }
    @chmod($dest, 0640);

    return 'storage/uploads/kyc/' . $userId . '/' . $name;
}

/**
 * Rate limit simple por archivo (IP + bucket). Fail-closed si no puede persistir.
 */
function rate_limit_allow(string $bucket, int $max, int $windowSeconds): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $dir = CREDIMAX_ROOT . '/storage/logs/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $file = $dir . '/' . hash('sha256', $bucket . '|' . $ip) . '.json';
    $now = time();
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return false;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }
        $raw = stream_get_contents($fp);
        $data = ['start' => $now, 'count' => 0];
        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
                $data = $parsed;
            }
        }
        if (($now - (int) $data['start']) >= $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }
        if ((int) $data['count'] >= $max) {
            return false;
        }
        $data['count'] = (int) $data['count'] + 1;
        rewind($fp);
        ftruncate($fp, 0);
        $written = fwrite($fp, (string) json_encode($data));
        fflush($fp);
        return $written !== false;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function asset(string $path): string
{
    $rel = 'assets/' . ltrim($path, '/');
    $url = url($rel);
    $file = (defined('CREDIMAX_ROOT') ? CREDIMAX_ROOT : dirname(__DIR__, 2)) . '/' . $rel;
    if (is_file($file)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
    }
    return $url;
}

function old(string $key, mixed $default = ''): mixed
{
    $old = Session::get('_old', []);
    return $old[$key] ?? $default;
}

function money(float|string|null $amount, bool $symbol = true): string
{
    $n = number_format((float) $amount, 2, ',', '.');
    return $symbol ? (App::config('currency_symbol', '$') . ' ' . $n) : $n;
}

/**
 * Convierte un monto escrito por el usuario a float.
 * Acepta "10.000,50" (es-AR), "10000.50" y "10 000,50"; descarta el resto.
 */
function parse_amount(string $raw): float
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0.0;
    }
    $clean = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';
    if ($clean === '') {
        return 0.0;
    }
    // Con coma presente, la coma es el separador decimal y el punto es de miles.
    if (str_contains($clean, ',')) {
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } elseif (substr_count($clean, '.') > 1) {
        $clean = str_replace('.', '', $clean);
    }
    $value = (float) $clean;
    return is_finite($value) ? round($value, 2) : 0.0;
}

function csrf_field(): string
{
    return Csrf::field();
}

/**
 * Token de un solo uso para formularios que mueven dinero.
 * A diferencia del CSRF (que se reutiliza durante la sesión), este cambia en cada
 * render, así un doble clic o un F5 reenvían la misma clave y la operación no se duplica.
 */
function idem_field(): string
{
    return '<input type="hidden" name="_idem" value="' . e(bin2hex(random_bytes(16))) . '">';
}

/** Lee el token de idempotencia del formulario y lo acota a la columna. */
function idem_key(string $scope): string
{
    $token = (string) ($_POST['_idem'] ?? '');
    if ($token === '' || !ctype_xdigit($token)) {
        return '';
    }
    return substr($scope . ':' . auth_id() . ':' . $token, 0, 64);
}

function auth_user(): ?array
{
    return Session::get('user');
}

function auth_id(): ?int
{
    $u = auth_user();
    return $u ? (int) $u['id'] : null;
}

function admin_role_definitions(): array
{
    return [
        'super_admin' => ['*'],
        'admin_treasury' => [
            'treasury.view', 'treasury.deposit_confirm', 'treasury.withdraw_confirm',
            'treasury.withdraw_reject', 'treasury.adjust_wallet', 'treasury.inject_own',
            'treasury.recalc_aum', 'treasury.run_overdue',
        ],
        'admin_kyc' => [
            'kyc.view', 'kyc.review', 'kyc.view_doc',
        ],
        'admin_support' => [
            'support.view_users', 'support.view_loans', 'support.view_funds',
            'support.view_tickets',
        ],
        'admin_audit' => [
            'audit.view', 'audit.export', 'logs.view',
        ],
    ];
}

function user_admin_role(?array $u = null): ?string
{
    $u ??= auth_user();
    if (!$u) {
        return null;
    }
    if (($u['role'] ?? '') === 'admin') {
        $explicit = (string) ($u['admin_role'] ?? '');
        return $explicit !== '' ? $explicit : 'super_admin';
    }
    $explicit = (string) ($u['admin_role'] ?? '');
    if ($explicit !== '' && $explicit !== 'none') {
        return $explicit;
    }
    return null;
}

function is_admin(): bool
{
    return user_admin_role() !== null;
}

function admin_has_permission(string $permission, ?array $u = null): bool
{
    $role = user_admin_role($u);
    if ($role === null) {
        return false;
    }
    $defs = admin_role_definitions();
    $perms = $defs[$role] ?? [];
    if (in_array('*', $perms, true)) {
        return true;
    }
    if (in_array($permission, $perms, true)) {
        return true;
    }
    $scope = explode('.', $permission, 2)[0] ?? '';
    return in_array($scope . '.*', $perms, true);
}

function require_auth(): void
{
    if (!auth_user()) {
        Session::flash('error', 'Debés iniciar sesión.');
        redirect(url('/login'));
    }
}

function require_admin(?string $atLeastRole = null): void
{
    require_auth();
    $role = user_admin_role();
    if ($role === null) {
        http_response_code(403);
        Session::flash('error', 'Acceso denegado.');
        redirect(url('/dashboard'));
    }
    if ($atLeastRole !== null && $role !== 'super_admin' && $role !== $atLeastRole) {
        http_response_code(403);
        Session::flash('error', 'Nivel de autorización insuficiente para esta operación.');
        redirect(url('/admin'));
    }
}

function require_permission(string $permission): void
{
    require_auth();
    if (!admin_has_permission($permission)) {
        http_response_code(403);
        Session::flash('error', 'No tenés permiso para realizar esta acción (' . $permission . ').');
        redirect(url('/admin'));
    }
}

function flash(string $type): ?string
{
    return Session::flash($type);
}

function generate_credimax_id(): string
{
    return 'CMX-' . strtoupper(bin2hex(random_bytes(4)));
}

function generate_reference(string $prefix = 'TX'): string
{
    return strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function generate_loan_code(): string
{
    return 'LN-' . strtoupper(bin2hex(random_bytes(5)));
}

function generate_qr_token(): string
{
    return hash('sha256', random_bytes(32) . microtime(true));
}

function status_label(string $status): string
{
    $map = [
        'pending' => 'Pendiente',
        'confirmed' => 'Confirmado',
        'submitted' => 'En revisión',
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado',
        'active' => 'Activo',
        'suspended' => 'Suspendido',
        'closed' => 'Cerrado',
        'open' => 'Abierto',
        'funding' => 'En fondeo',
        'funded' => 'Fondeado',
        'completed' => 'Completado',
        'defaulted' => 'En mora',
        'cancelled' => 'Cancelado',
        'draft' => 'Borrador',
        'partial' => 'Parcial',
        'paid' => 'Pagado',
        'overdue' => 'Vencido',
        'reserved' => 'Reservado',
        'frozen' => 'Congelado',
        'expired' => 'Vencido',
        'queued' => 'En cola',
        'sent' => 'Transferido',
        'failed' => 'Fallido',
        'linked' => 'Vinculada',
        'unlinked' => 'Sin vincular',
    ];
    return $map[$status] ?? ucfirst($status);
}

function risk_band_badge(?string $band, bool $withLabel = true): string
{
    $band = (string) ($band ?? 'NR');
    if ($band === '' || $band === '0') $band = 'NR';
    $palette = [
        'A+' => ['#0b4f2a', '#d1fae5', 'AAA · Riesgo mínimo'],
        'A'  => ['#166534', '#dcfce7', 'A+ · Excelente perfil'],
        'B'  => ['#156082', '#dbeafe', 'B · Perfil sólido'],
        'C'  => ['#713f12', '#fef3c7', 'C · Perfil moderado'],
        'D'  => ['#92400e', '#fed7aa', 'D · Perfil atento'],
        'E'  => ['#7f1d1d', '#fecaca', 'E · Perfil vigilado'],
        'F'  => ['#450a0a', '#fda4af', 'F · Alto riesgo'],
        'NR' => ['#374151', '#e5e7eb', 'Sin calificación'],
    ];
    $k = $band;
    if (!isset($palette[$k])) $k = 'NR';
    [$fg, $bg, $label] = $palette[$k];
    $html = '<span class="badge" style="background-color:' . $bg . ';color:' . $fg . ';border:1px solid ' . $bg . ';font-weight:700;padding:.25rem .6rem;border-radius:999px;display:inline-block;letter-spacing:.02em">' . e($band);
    if ($withLabel) $html .= ' · ' . e($label);
    $html .= '</span>';
    return $html;
}

function loan_schedule(float $principal, float $annualRate, int $months): array
{
    if ($months < 1) {
        throw new InvalidArgumentException('Plazo inválido');
    }
    $monthlyRate = ($annualRate / 100) / 12;
    if ($monthlyRate <= 0) {
        $installment = round($principal / $months, 2);
    } else {
        $installment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $months)) / (pow(1 + $monthlyRate, $months) - 1);
        $installment = round($installment, 2);
    }

    $balance = $principal;
    $rows = [];
    $start = new DateTimeImmutable('first day of next month');

    for ($i = 1; $i <= $months; $i++) {
        $interest = round($balance * $monthlyRate, 2);
        $principalPortion = round($installment - $interest, 2);
        if ($i === $months) {
            $principalPortion = round($balance, 2);
            $installment = round($principalPortion + $interest, 2);
        }
        $due = $start->modify('+' . ($i - 1) . ' months')->format('Y-m-d');
        $rows[] = [
            'number' => $i,
            'due_date' => $due,
            'principal' => $principalPortion,
            'interest' => $interest,
            'total' => round($principalPortion + $interest, 2),
        ];
        $balance = round($balance - $principalPortion, 2);
    }

    $totalPayable = array_sum(array_column($rows, 'total'));
    return [
        'installment' => $rows[0]['total'] ?? $installment,
        'total_payable' => round($totalPayable, 2),
        'rows' => $rows,
    ];
}

/**
 * TIR mensual (Newton) que iguala desembolso con una serie de pagos mensuales.
 */
function loan_monthly_irr(float $disbursement, array $payments, float $guess = 0.05): float
{
    $r = $guess;
    $n = count($payments);
    if ($disbursement <= 0 || $n < 1) {
        return 0.0;
    }
    for ($i = 0; $i < 80; $i++) {
        $npv = $disbursement;
        $dNpv = 0.0;
        for ($k = 1; $k <= $n; $k++) {
            $pmt = (float) $payments[$k - 1];
            $df = pow(1 + $r, $k);
            $npv -= $pmt / $df;
            $dNpv += $k * $pmt / ($df * (1 + $r));
        }
        if (abs($dNpv) < 1e-12) {
            break;
        }
        $next = $r - $npv / $dNpv;
        if (abs($next - $r) < 1e-12) {
            $r = $next;
            break;
        }
        $r = $next;
    }
    return max(0.0, $r);
}

/**
 * Cotización Credimax: el cliente recibe el monto pedido completo.
 * Gastos (comisión de originación) se capitalizan y se amortizan en las cuotas (sistema francés).
 *
 * Indicadores según práctica BCRA / exposición tipo BNA:
 * - TNA: tasa nominal anual pactada
 * - TEA: (1 + TNA/12)^12 − 1
 * - CFT TNA / CFT TEA: costo financiero total (comisión + IVA 21% sobre interés y comisión),
 *   con CFT TEA = (1 + r_mensual)^12 − 1 y CFT TNA = r_mensual × 12
 *
 * @return array{
 *   disbursement: float,
 *   origination_fee: float,
 *   financed_principal: float,
 *   installment: float,
 *   installment_with_iva: float,
 *   total_payable: float,
 *   total_payable_with_iva: float,
 *   tna: float,
 *   tea: float,
 *   cft_tna: float,
 *   cft_tea: float,
 *   cft_approx: float,
 *   iva_pct: float,
 *   rate_type: string,
 *   rows: list<array>
 * }
 */
function loan_quote(float $requestedAmount, float $annualRate, int $months, float $feePct = 0.0, ?float $ivaPct = null): array
{
    if ($requestedAmount <= 0) {
        throw new InvalidArgumentException('Monto inválido');
    }
    $feePct = max(0.0, $feePct);
    $ivaPct = $ivaPct ?? (float) App::config('credit.iva_pct', 21.0);
    $ivaFactor = 1 + max(0.0, $ivaPct) / 100;

    $origFee = round($requestedAmount * $feePct / 100, 2);
    $financed = round($requestedAmount + $origFee, 2);
    $schedule = loan_schedule($financed, $annualRate, $months);

    // Reparto contable de la comisión dentro de la porción capital de cada cuota
    $rows = [];
    $paymentsWithIva = [];
    $feeLeft = $origFee;
    $count = count($schedule['rows']);
    foreach ($schedule['rows'] as $idx => $row) {
        if ($financed > 0) {
            $feePart = ($idx === $count - 1)
                ? round($feeLeft, 2)
                : round($row['principal'] * ($origFee / $financed), 2);
            $feeLeft = round($feeLeft - $feePart, 2);
        } else {
            $feePart = 0.0;
        }
        $principalPart = round($row['principal'] - $feePart, 2);
        $interest = (float) $row['interest'];
        // CFT: IVA sobre intereses y comisión (BCRA punto 3.4.2.7 + cargos asociados)
        $ivaAmount = round(($interest + $feePart) * ($ivaFactor - 1), 2);
        $totalWithIva = round($principalPart + $interest + $feePart + $ivaAmount, 2);
        $paymentsWithIva[] = $totalWithIva;
        $rows[] = [
            'number' => $row['number'],
            'due_date' => $row['due_date'],
            'principal' => $principalPart,
            'interest' => $interest,
            'fee' => $feePart,
            'iva' => $ivaAmount,
            'total' => $row['total'],
            'total_with_iva' => $totalWithIva,
        ];
    }

    // TEA solo interés (BCRA 3.3 / BNA): capitalización mensual sobre TNA
    $tea = (pow(1 + ($annualRate / 100) / 12, 12) - 1) * 100;

    // CFT TEA = TIR anualizada de flujos con comisión e IVA; CFT TNA = 12 × tasa mensual
    $rCft = loan_monthly_irr($requestedAmount, $paymentsWithIva, ($annualRate / 100) / 12 * $ivaFactor);
    $cftTea = (pow(1 + $rCft, 12) - 1) * 100;
    $cftTna = $rCft * 12 * 100;

    $totalWithIva = round(array_sum($paymentsWithIva), 2);

    return [
        'disbursement' => round($requestedAmount, 2),
        'origination_fee' => $origFee,
        'financed_principal' => $financed,
        'installment' => $schedule['installment'],
        'installment_with_iva' => $rows[0]['total_with_iva'] ?? $schedule['installment'],
        'total_payable' => $schedule['total_payable'],
        'total_payable_with_iva' => $totalWithIva,
        'tna' => round($annualRate, 2),
        'tea' => round($tea, 2),
        'cft_tna' => round($cftTna, 2),
        'cft_tea' => round($cftTea, 2),
        'cft_approx' => round($cftTea, 2), // alias destacado = CFT TEA (con IVA)
        'iva_pct' => round($ivaPct, 2),
        'rate_type' => 'fija',
        'rows' => $rows,
    ];
}

/**
 * Estimación orientativa de retorno para un inversor (estilo Afluenta).
 * Asume cobro completo de su proporción; no es garantía de rendimiento.
 *
 * @return array{amount: float, months: int, tna: float, band: string, expected_interest: float, expected_total: float, tea: float, monthly_approx: float}
 */
function invest_quote(float $amount, float $annualRate, int $months, string $band = 'C', float $platformFeePct = 0.0): array
{
    if ($amount <= 0 || $months < 1) {
        throw new InvalidArgumentException('Parámetros de inversión inválidos');
    }
    $schedule = loan_schedule($amount, $annualRate, $months);
    $grossInterest = round($schedule['total_payable'] - $amount, 2);
    $fee = round($grossInterest * max(0, $platformFeePct) / 100, 2);
    $netInterest = round($grossInterest - $fee, 2);
    $tea = (pow(1 + ($annualRate / 100) / 12, 12) - 1) * 100;
    return [
        'amount' => round($amount, 2),
        'months' => $months,
        'tna' => round($annualRate, 2),
        'band' => $band,
        'band_label' => \App\Services\ScoringService::bandLabel($band),
        'expected_interest' => $netInterest,
        'expected_total' => round($amount + $netInterest, 2),
        'tea' => round($tea, 2),
        'monthly_approx' => round($schedule['installment'], 2),
        'platform_fee' => $fee,
        'disclaimer' => 'Estimación orientativa. Invertir implica riesgo de incobrabilidad; el retorno efectivo puede ser menor o negativo.',
    ];
}

function audit_log(string $action, ?string $entityType = null, ?string $entityId = null, ?array $meta = null): void
{
    try {
        App::db()->insert('audit_logs', [
            'user_id' => auth_id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function notify(int $userId, string $title, string $body, ?string $link = null): void
{
    App::db()->insert('notifications', [
        'user_id' => $userId,
        'title' => $title,
        'body' => $body,
        'link' => $link,
    ]);
}

function otp_threshold_for_user(?array $user = null): float
{
    $user ??= auth_user();
    if (!$user) {
        return 50000.0;
    }
    $thr = $user['otp_threshold_amount'] ?? null;
    if (is_numeric($thr)) {
        return (float) $thr;
    }
    return 50000.0;
}

function otp_requires_for_user(float $amount, ?array $user = null): bool
{
    if ($amount <= 0) {
        return false;
    }
    return $amount >= otp_threshold_for_user($user);
}

function otp_session_scope_key(string $scope): string
{
    return '_otp_pending:' . $scope;
}

function otp_ensure_session_challenge(string $scope, float $amount, ?array $extra = null): bool
{
    $uid = auth_id();
    if ($uid === null) {
        return false;
    }
    $user = App::db()->fetch('SELECT two_factor_mode, email, phone FROM users WHERE id = ?', [$uid]);
    $mode = (string) ($user['two_factor_mode'] ?? 'email_otp');
    $key = otp_session_scope_key($scope);
    $existing = \App\Core\Session::get($key);
    if (is_array($existing)
        && !empty($existing['nonce'])
        && (int) ($existing['expires_at'] ?? 0) >= time()
        && (float) ($existing['amount'] ?? 0.0) >= $amount - 0.01
    ) {
        return true;
    }
    $nonce = bin2hex(random_bytes(16));
    $expires = time() + 900;
    $channel = $mode === 'sms_otp' || $mode === 'totp' ? $mode : 'email_otp';
    if ($channel === 'totp') {
        \App\Core\Session::set($key, [
            'nonce' => $nonce,
            'expires_at' => $expires,
            'amount' => $amount,
            'channel' => 'totp',
            'extra' => $extra,
        ]);
        return true;
    }
    try {
        $svc = new \App\Services\OtpService();
        $svc->send($uid, $channel === 'sms_otp' ? 'sms' : 'email');
    } catch (Throwable $e) {
        return false;
    }
    \App\Core\Session::set($key, [
        'nonce' => $nonce,
        'expires_at' => $expires,
        'amount' => $amount,
        'channel' => $channel,
        'extra' => $extra,
    ]);
    return true;
}

function otp_verify_challenge(string $scope, ?string $code): bool
{
    $uid = auth_id();
    if ($uid === null || $code === null || trim($code) === '') {
        return false;
    }
    $key = otp_session_scope_key($scope);
    $pending = \App\Core\Session::get($key);
    if (!is_array($pending) || empty($pending['nonce']) || (int) ($pending['expires_at'] ?? 0) < time()) {
        return false;
    }
    try {
        if (($pending['channel'] ?? '') === 'totp') {
            $totp = new \App\Services\TotpService();
            $ok = $totp->verifyForUser($uid, trim($code));
        } else {
            $svc = new \App\Services\OtpService();
            $svc->verify($uid, $pending['channel'] === 'sms_otp' ? 'sms' : 'email', trim($code));
            $ok = true;
        }
        if ($ok) {
            \App\Core\Session::forget($key);
            return true;
        }
    } catch (Throwable $e) {
    }
    return false;
}

function otp_redirect_if_needed(string $scope, float $amount, string $redirectBack, ?array $extra = null): ?string
{
    if (!otp_requires_for_user($amount)) {
        return null;
    }
    $code = (string) ($_POST['_otp'] ?? '');
    if ($code !== '' && otp_verify_challenge($scope, $code)) {
        return null;
    }
    otp_ensure_session_challenge($scope, $amount, $extra);
    \App\Core\Session::flash('info', 'Esta operación requiere verificación. Ingresá el código que enviamos por '
        . (((\App\Core\Session::get(otp_session_scope_key($scope))['channel'] ?? '') === 'sms_otp') ? 'SMS' : 'email')
        . ' o tu código TOTP.');
    \App\Core\Session::set('_otp_pending_back', $redirectBack);
    \App\Core\Session::set('_otp_pending_scope', $scope);
    redirect(url('/otp/verify'));
}

function csp_nonce(): string
{
    $key = '_csp_nonce';
    $n = \App\Core\Session::get($key);
    if (is_string($n) && strlen($n) === 48) {
        return $n;
    }
    $n = bin2hex(random_bytes(24));
    \App\Core\Session::set($key, $n);
    return $n;
}

function kyc_decrypt_file_contents(array $doc): string
{
    $scheme = (string) ($doc['encryption_scheme'] ?? 'plain');
    if ($scheme === 'plain' || $scheme === '') {
        $path = CREDIMAX_ROOT . '/' . ltrim((string) str_replace('\\', '/', (string) ($doc['file_path'] ?? '')), '/');
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer el documento.');
        }
        return $contents;
    }
    if ($scheme !== 'aes-256-gcm') {
        throw new RuntimeException('Esquema de cifrado desconocido.');
    }
    $path = CREDIMAX_ROOT . '/' . ltrim((string) str_replace('\\', '/', (string) ($doc['file_path'] ?? '')), '/');
    $cipher = @file_get_contents($path);
    if ($cipher === false || $cipher === '') {
        throw new RuntimeException('No se pudo leer el documento cifrado.');
    }
    $iv = (string) ($doc['cipher_iv'] ?? '');
    $tag = (string) ($doc['cipher_tag'] ?? '');
    if ($iv === '' || $tag === '' || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Metadatos de descifrado faltantes.');
    }
    $ivBin = @hex2bin($iv);
    $tagBin = @hex2bin($tag);
    if ($ivBin === false || $tagBin === false) {
        $ivBin = base64_decode($iv, true) ?: '';
        $tagBin = base64_decode($tag, true) ?: '';
    }
    if (!is_string($ivBin) || strlen($ivBin) !== 12 || !is_string($tagBin) || strlen($tagBin) !== 16) {
        throw new RuntimeException('IV/Tag inválidos.');
    }
    $appKey = (string) \App\Core\App::config('security.app_key', '');
    if (strlen($appKey) < 16) {
        throw new RuntimeException('security.app_key insuficiente.');
    }
    $symKey = hash_hkdf('sha256', $appKey, 32, 'kyc-storage-v1', 'credimax-kyc-v1');
    $aad = 'credimax-kyc-doc:' . ((int) ($doc['id'] ?? 0)) . ':' . ((int) ($doc['user_id'] ?? 0)) . ':v1';
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $symKey, OPENSSL_RAW_DATA, $ivBin, $tagBin, $aad);
    if ($plain === false) {
        throw new RuntimeException('No se pudo descifrar el documento. La clave de la app pudo haber cambiado.');
    }
    $expectedSize = (int) ($doc['original_size'] ?? 0);
    if ($expectedSize > 0 && strlen($plain) !== $expectedSize) {
        // tolerancia; no fallar
    }
    return $plain;
}

function csv_emit(string $filename, array $rows, array $headers = []): never
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('No se pudo abrir salida CSV.');
    }
    fprintf($out, "\xEF\xBB\xBF");
    if ($headers !== []) {
        fputcsv($out, $headers, ';');
    }
    foreach ($rows as $r) {
        if (is_array($r)) {
            fputcsv($out, $r, ';');
        }
    }
    fclose($out);
    exit;
}

function totp_issuer_label(): string
{
    return (string) \App\Core\App::config('app_name', 'Credimax');
}

function otp_pending_scope(): ?string
{
    $scope = \App\Core\Session::get('_otp_pending_scope');
    return is_string($scope) && $scope !== '' ? $scope : null;
}

function otp_pending_extra(string $field, mixed $default = null): mixed
{
    $scope = otp_pending_scope();
    if ($scope === null) {
        return $default;
    }
    $key = otp_session_scope_key($scope);
    $pending = \App\Core\Session::get($key);
    if (!is_array($pending) || !isset($pending['extra']) || !is_array($pending['extra'])) {
        return $default;
    }
    return $pending['extra'][$field] ?? $default;
}

function otp_pending_amount(): float
{
    $scope = otp_pending_scope();
    if ($scope === null) {
        return 0.0;
    }
    $key = otp_session_scope_key($scope);
    $pending = \App\Core\Session::get($key);
    if (!is_array($pending)) {
        return 0.0;
    }
    return (float) ($pending['amount'] ?? 0.0);
}

