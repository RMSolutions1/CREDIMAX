<?php
declare(strict_types=1);

/**
 * Credimax — Front controller
 * Compatible con hosting compartido / FTP + PHP + MySQL
 */

require __DIR__ . '/app/bootstrap.php';

// Headers de seguridad
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 0');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://images.unsplash.com https://chart.googleapis.com https://api.qrserver.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline'; connect-src 'self' https://api.qrserver.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; worker-src 'self'; manifest-src 'self'");
if (request_is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if (auth_user()) {
    (new App\Services\AuthService())->refreshSession();
}

$router = require __DIR__ . '/app/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
