<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\View;

final class HealthController
{
    public function index(): void
    {
        $ok = true;
        $checks = ['app' => 'ok'];
        try {
            App::db()->fetch('SELECT 1 AS ok');
            $checks['db'] = 'ok';
        } catch (\Throwable $e) {
            $ok = false;
            $checks['db'] = 'fail';
        }
        View::json([
            'status' => $ok ? 'ok' : 'degraded',
            'app' => (string) App::config('app_name', 'Credimax'),
            'version' => defined('CREDIMAX_VERSION') ? CREDIMAX_VERSION : null,
            'checks' => $checks,
            'time' => date('c'),
        ], $ok ? 200 : 503);
    }
}
