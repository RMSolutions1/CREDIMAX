<?php
/**
 * Credimax — plantilla de producción
 * Copiá como config.php y reemplazá TODOS los valores CAMBIAR_*
 */
return [
    'app_name' => 'Credimax',
    'app_url' => 'https://unipagos.online', // sin barra final, con HTTPS
    'app_env' => 'production',
    'app_debug' => false,
    'timezone' => 'America/Argentina/Buenos_Aires',
    'locale' => 'es_AR',
    'currency' => 'ARS',
    'currency_symbol' => '$',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'credimax',
        'user' => 'credimax_user',
        'pass' => 'CAMBIAR_PASSWORD_DB',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'CREDIMAXSESSID',
        'csrf_key' => 'credimax_csrf',
        'password_algo' => PASSWORD_DEFAULT,
        'login_max_attempts' => 8,
        'login_lock_minutes' => 15,
        'jwt_secret' => 'CAMBIAR_JWT_SECRET_MIN_32_CHARS_RANDOM',
        'cron_key' => 'CAMBIAR_CRON_KEY_LARGA_Y_ALEATORIA',
        // Cifra los tokens de Mercado Pago guardados en DB. bin2hex(random_bytes(32))
        // Si la cambiás, las cuentas vinculadas deben volver a vincularse.
        'app_key' => 'CAMBIAR_APP_KEY_MIN_32_CHARS_RANDOM',
        'install_locked' => true,
        // IPs del reverse proxy que pueden enviar X-Forwarded-Proto (vacío = no confiar).
        'trusted_proxies' => [],
    ],

    /**
     * Mercado Pago — billetera Credimax como sub-cuenta de la cuenta madre.
     *
     * Lo más práctico es dejar esto vacío y cargar las credenciales desde
     * /admin/mercadopago: quedan en la tabla settings y tienen prioridad sobre
     * este archivo, así no hay secretos en el código ni en el repositorio.
     *
     * Panel: https://www.mercadopago.com.ar/developers/panel/app
     *  - access_token / public_key : credenciales de producción de la aplicación
     *  - client_id / client_secret : necesarios solo para vincular cuentas (OAuth)
     *  - webhook_secret            : Webhooks → Configurar notificación → clave secreta
     *
     * URL de webhook a registrar : https://unipagos.online/webhooks/mercadopago
     * URL de redirección OAuth   : https://unipagos.online/wallet/mp/vincular/callback
     */
    'mercadopago' => [
        'enabled' => false,
        'site_id' => 'MLA',
        'access_token' => '',
        'public_key' => '',
        'client_id' => '',
        'client_secret' => '',
        'webhook_secret' => '',
        'redirect_uri' => '',
        'statement_descriptor' => 'CREDIMAX',
        'binary_mode' => false,
        'expiration_minutes' => 60,
        'charge_expiration_minutes' => 1440,
        // absorb: se acredita el bruto y la plataforma paga la comisión de MP.
        // transfer: se acredita el neto recibido y la comisión la paga el usuario.
        'topup_fee_mode' => 'absorb',
        'charge_fee_pct' => 0.0,
        'max_installments' => 12,
        // Ej: ['ticket'] deja fuera el efectivo (Rapipago/Pago Fácil), que acredita en días.
        'excluded_payment_types' => [],
        'excluded_payment_methods' => [],
    ],

    'wallet' => [
        'min_deposit' => 100,
        'max_deposit' => 5000000,
        'min_withdraw' => 100,
        'max_withdraw' => 5000000,
        'min_transfer' => 50,
        'platform_fee_pct' => 1.5,
    ],

    'credit' => [
        'iva_pct' => 21.0,
        'rate_reference_amount' => 1000000.0,
        'rate_reference_months' => 12,
    ],

    'mail' => [
        'enabled' => true,
        'from' => 'noreply@unipagos.online',
        'from_name' => 'Credimax',
    ],

    'uploads' => [
        'max_mb' => 5,
        'allowed' => ['jpg', 'jpeg', 'png', 'pdf', 'webp'],
    ],

    'logs' => [
        'audit_retention_days' => 365,
        'webhook_retention_days' => 180,
        'otp_retention_days' => 60,
        'notification_retention_days' => 180,
    ],
];
