<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\StatsService;

final class HomeController
{
    public function index(): void
    {
        if (auth_user()) {
            redirect(url('/dashboard'));
        }
        $stats = (new StatsService())->publicSnapshot();
        View::render('home/index', [
            'title' => 'Créditos e inversiones entre personas en Argentina',
            'metaDescription' => 'Credimax: la plataforma argentina de créditos P2P hecha para liderar en claridad. Desembolso 100%, TNA desde 36%, CFT transparente. Simulá e invertí online en ARS.',
            'stats' => $stats,
        ], 'layouts/marketing');
    }
}
