<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

final class SiteController
{
    public function howItWorks(): void
    {
        View::render('site/how', ['title' => 'Cómo funciona'], 'layouts/marketing');
    }

    public function products(): void
    {
        $products = $this->productsSafe();
        View::render('site/products', ['title' => 'Productos', 'products' => $products], 'layouts/marketing');
    }

    public function investLanding(): void
    {
        $bands = \App\Services\ScoringService::BAND_TNA;
        View::render('site/invest', [
            'title' => 'Invertí en créditos entre personas',
            'bands' => $bands,
        ], 'layouts/marketing');
    }

    public function borrowLanding(): void
    {
        View::render('site/borrow', ['title' => 'Pedí tu crédito online'], 'layouts/marketing');
    }

    public function pymeLanding(): void
    {
        View::render('site/pyme', ['title' => 'Créditos para PyME'], 'layouts/marketing');
    }

    public function stats(): void
    {
        $stats = (new \App\Services\StatsService())->publicSnapshot();
        View::render('site/stats', ['title' => 'Estadísticas del sistema', 'stats' => $stats], 'layouts/marketing');
    }

    public function investSimulator(): void
    {
        View::render('site/simulator-invest', [
            'title' => 'Simulador de inversión',
            'bands' => \App\Services\ScoringService::BAND_TNA,
            'labels' => \App\Services\ScoringService::BAND_LABELS,
        ], 'layouts/marketing');
    }

    public function investSimulatorCalc(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $amount = (float) ($_GET['amount'] ?? 0);
        $months = (int) ($_GET['months'] ?? 12);
        $band = strtoupper(trim((string) ($_GET['band'] ?? 'C')));
        $rate = (float) ($_GET['rate'] ?? 0);
        if ($rate <= 0) {
            $rate = \App\Services\ScoringService::suggestedTna($band);
        }
        $fee = (float) App::config('wallet.platform_fee_pct', 1.5);
        try {
            $q = invest_quote($amount, $rate, $months, $band, $fee);
            echo json_encode(['ok' => true] + $q, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function regretForm(): void
    {
        View::render('legal/arrepentimiento', ['title' => 'Botón de arrepentimiento'], 'layouts/marketing');
    }

    public function regretSubmit(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            (new \App\Services\AccountClosureService())->requestRegret(
                auth_id(),
                trim((string) ($_POST['reason'] ?? ''))
            );
            Session::flash('success', 'Solicitud de arrepentimiento registrada.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/legales/arrepentimiento'));
    }

    public function fideicomiso(): void
    {
        View::render('legal/fideicomiso', ['title' => 'Fideicomiso y segregación de fondos'], 'layouts/marketing');
    }

    public function adhesion(): void
    {
        View::render('legal/adhesion', ['title' => 'Contrato de adhesión'], 'layouts/marketing');
    }

    public function costs(): void
    {
        View::render('site/costs', ['title' => 'Costos de nuestros servicios'], 'layouts/marketing');
    }

    public function why(): void
    {
        View::render('site/why', ['title' => 'Por qué Credimax'], 'layouts/marketing');
    }

    public function requirements(): void
    {
        View::render('site/requirements', ['title' => 'Requisitos'], 'layouts/marketing');
    }

    public function rates(): void
    {
        $products = $this->productsSafe();
        $refAmount = (float) App::config('credit.rate_reference_amount', 1000000);
        $refMonths = (int) App::config('credit.rate_reference_months', 12);
        $rows = [];
        foreach ($products as $p) {
            $months = min(max($refMonths, (int) $p['min_term_months']), (int) $p['max_term_months']);
            $amount = min(max($refAmount, (float) $p['min_amount']), (float) $p['max_amount']);
            try {
                $quote = loan_quote($amount, (float) $p['annual_rate'], $months, (float) $p['origination_fee_pct']);
            } catch (\Throwable $e) {
                $quote = null;
            }
            $rows[] = [
                'product' => $p,
                'quote' => $quote,
                'ref_amount' => $amount,
                'ref_months' => $months,
            ];
        }
        View::render('site/rates', [
            'title' => 'Tasas y costos',
            'products' => $products,
            'rate_rows' => $rows,
            'ref_amount' => $refAmount,
            'ref_months' => $refMonths,
        ], 'layouts/marketing');
    }

    public function security(): void
    {
        View::render('site/security', ['title' => 'Seguridad'], 'layouts/marketing');
    }

    public function about(): void
    {
        View::render('site/about', ['title' => 'Nosotros'], 'layouts/marketing');
    }

    public function sitemap(): void
    {
        View::render('site/sitemap', ['title' => 'Mapa del sitio'], 'layouts/marketing');
    }

    public function sitemapXml(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = rtrim((string) App::config('app_url', ''), '/');
        $paths = [
            '/', '/pedir-credito', '/invertir', '/por-que-credimax', '/pyme', '/simulador',
            '/simulador-inversion', '/tasas', '/costos', '/estadisticas', '/como-funciona',
            '/productos', '/requisitos', '/nosotros', '/seguridad', '/faq', '/ayuda', '/contacto',
            '/legales/terminos', '/legales/adhesion', '/legales/fideicomiso', '/legales/cumplimiento',
            '/legales/privacidad', '/legales/defensa-consumidor', '/legales/arrepentimiento',
            '/legales/baja', '/legales/usuario-financiero', '/mapa-del-sitio',
        ];
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($paths as $p) {
            $loc = htmlspecialchars($base . $p, ENT_XML1);
            echo "  <url><loc>{$loc}</loc><changefreq>weekly</changefreq></url>\n";
        }
        echo "</urlset>";
    }

    public function robotsTxt(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $base = rtrim((string) App::config('app_url', ''), '/');
        echo "User-agent: *\nAllow: /\n\n";
        echo "Sitemap: {$base}/sitemap.xml\n\n";
        echo "Disallow: /install/\nDisallow: /storage/\nDisallow: /app/\nDisallow: /config/\n";
        echo "Disallow: /database/\nDisallow: /migrate_production.php\nDisallow: /migrate_banking.php\n";
        echo "Disallow: /migrate_mercadopago.php\nDisallow: /diagnostico.php\nDisallow: /seed_usuarios.php\n";
        echo "Disallow: /mp_smoketest.php\nDisallow: /cron.php\n";
    }

    public function faq(): void
    {
        View::render('site/faq', ['title' => 'Preguntas frecuentes'], 'layouts/marketing');
    }

    public function help(): void
    {
        View::render('site/help', ['title' => 'Centro de ayuda'], 'layouts/marketing');
    }

    public function contact(): void
    {
        View::render('site/contact', ['title' => 'Contacto'], 'layouts/marketing');
    }

    public function contactSend(): void
    {
        Csrf::requireValid();
        if (!rate_limit_allow('contact', 5, 600)) {
            Session::flash('error', 'Demasiados mensajes. Esperá unos minutos e intentá de nuevo.');
            redirect(url('/contacto'));
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $msg = trim((string) ($_POST['message'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($msg) < 10) {
            Session::flash('error', 'Completá nombre, email válido y un mensaje.');
            redirect(url('/contacto'));
        }
        try {
            App::db()->insert('support_tickets', [
                'user_id' => auth_id(),
                'name' => $name,
                'email' => $email,
                'subject' => trim((string) ($_POST['subject'] ?? 'Consulta web')),
                'message' => $msg,
                'status' => 'open',
            ]);
        } catch (\Throwable $e) {
            @file_put_contents(CREDIMAX_ROOT . '/storage/logs/contact.log', date('c') . " $email $msg\n", FILE_APPEND);
        }
        Session::flash('success', 'Recibimos tu mensaje. Te responderemos a la brevedad.');
        redirect(url('/contacto'));
    }

    public function terms(): void
    {
        View::render('legal/terms', ['title' => 'Términos y Condiciones'], 'layouts/marketing');
    }

    public function privacy(): void
    {
        View::render('legal/privacy', ['title' => 'Política de Privacidad'], 'layouts/marketing');
    }

    public function cookies(): void
    {
        View::render('legal/cookies', ['title' => 'Política de Cookies'], 'layouts/marketing');
    }

    public function loanContract(): void
    {
        View::render('legal/loan-contract', ['title' => 'Contrato de crédito'], 'layouts/marketing');
    }

    public function operatingManual(): void
    {
        View::render('legal/manual', ['title' => 'Manual operativo P2P'], 'layouts/marketing');
    }

    public function pep(): void
    {
        View::render('legal/pep', ['title' => 'Declaración PEP'], 'layouts/marketing');
    }

    public function consumer(): void
    {
        View::render('legal/consumer', ['title' => 'Defensa del Consumidor'], 'layouts/marketing');
    }

    public function compliance(): void
    {
        View::render('legal/compliance', ['title' => 'Marco regulatorio'], 'layouts/marketing');
    }

    public function usuarioFinanciero(): void
    {
        View::render('legal/usuario-financiero', ['title' => 'Información al usuario financiero'], 'layouts/marketing');
    }

    public function simulator(): void
    {
        View::render('site/simulator', ['title' => 'Simulador de crédito', 'products' => $this->productsSafe()], 'layouts/marketing');
    }

    public function simulatorCalc(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $amount = (float) ($_GET['amount'] ?? 0);
        $months = (int) ($_GET['months'] ?? 12);
        $rate = (float) ($_GET['rate'] ?? 48);
        $fee = (float) ($_GET['fee'] ?? 2.5);
        if ($amount <= 0 || $months < 1) {
            echo json_encode(['ok' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        try {
            $quote = loan_quote($amount, $rate, $months, $fee);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }
        echo json_encode([
            'ok' => true,
            'installment' => $quote['installment'],
            'installment_with_iva' => $quote['installment_with_iva'],
            'total_payable' => $quote['total_payable'],
            'total_payable_with_iva' => $quote['total_payable_with_iva'],
            'origination_fee' => $quote['origination_fee'],
            'financed_principal' => $quote['financed_principal'],
            'net_disbursement' => $quote['disbursement'],
            'disbursement' => $quote['disbursement'],
            'tna' => $quote['tna'],
            'tea' => $quote['tea'],
            'cft_tna' => $quote['cft_tna'],
            'cft_tea' => $quote['cft_tea'],
            'cft_approx' => $quote['cft_tea'],
            'iva_pct' => $quote['iva_pct'],
            'rate_type' => $quote['rate_type'],
            'rows' => array_slice($quote['rows'], 0, 3),
            'installments_count' => count($quote['rows']),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function productsSafe(): array
    {
        try {
            return App::db()->fetchAll('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY id');
        } catch (\Throwable $e) {
            return [];
        }
    }
}
