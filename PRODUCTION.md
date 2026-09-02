# Credimax — checklist de producción

Este documento define lo que la **app** cubre y lo que **sigue siendo obligatorio fuera del código** (legal / banco / UIF).

## Listo en software (operación ledger)

- Préstamos P2P: alta, fondeo manual, desembolso 100%, cuotas, mora (cron)
- Billetera: depósito informado → confirmación admin; retiro a CBU/alias → pago admin
- KYC + PEP + scoring interno
- TNA / TEA / CFT TNA / CFT TEA (IVA 21%)
- Auth: CSRF, hash, lock de login, reset de contraseña, JWT secret configurable
- OTP por email (MailService); sin código en UI
- Headers de seguridad (CSP, HSTS en HTTPS)
- Audit log + notificaciones in-app
- Instalador bloqueable (`security.install_locked` / `storage/INSTALL_LOCKED`)

## Obligatorio antes de oferta pública (no es código)

1. Inscripción / asesoramiento **PSCPP BCRA** con estudio jurídico
2. Encuadre **UIF** (oficial de cumplimiento, manual PLA/FT, ROS)
3. **Cuenta bancaria/PSP segregada** a nombre de la plataforma + conciliación diaria ledger↔banco
4. Contrato societario, CUIT, términos revisados por abogado
5. Proveedor **email/SMS** productivo (`mail.enabled=true` + SMTP del hosting)
6. HTTPS + backups MySQL diarios + retención de KYC

## Deploy rápido

1. Subir archivos por FTP/SFTP
2. Crear DB y usuario MySQL con password fuerte
3. Copiar `config/config.sample.php` → `config/config.php` y completar:
   - `app_url` = `https://unipagos.online`
   - `app_env=production`, `app_debug=false`
   - `security.jwt_secret` (≥32 chars random)
   - `security.cron_key`
   - `security.app_key` (≥32 chars random; no cambiar después de vincular cuentas MP)
   - `security.install_locked=true`
   - `mail.*`
4. Importar `database/schema.sql` **o** correr `php migrate_production.php`
5. Crear el administrador por el instalador antes de bloquearlo, o directamente mediante una operación controlada de base de datos.
6. Confirmar que `.htaccess` está activo: bloquea `/install`, `storage/`, migraciones, seeds, cron y diagnósticos por HTTP. Ejecutar esas tareas solo por CLI.
7. Cron diario por CLI: `php /ruta/cron.php`. No exponer el cron por HTTP.
8. Configurar backup diario de MySQL y de `storage/uploads/kyc/`, con una restauración probada.
9. Probar: registro → KYC admin → depósito → crédito → fondeo → pago → retiro.

La aplicación falla de forma segura al iniciar en `production` si faltan HTTPS, secretos
aleatorios, credenciales de base de datos, el lock del instalador o si `app_debug` quedó activo.

## Mercado Pago (producción)

1. Activar credenciales **live** de la app en el panel de desarrolladores.
2. Cargar `access_token`, `public_key`, `client_id`, `client_secret` y `webhook_secret` en `/admin/mercadopago`.
3. Registrar webhook HTTPS: `https://unipagos.online/webhooks/mercadopago` (tópicos payment / merchant_order).
   Redirect OAuth: `https://unipagos.online/wallet/mp/vincular/callback`.
4. En producción el webhook **rechaza** notificaciones si falta el secreto o la firma HMAC.
5. `php migrate_mercadopago.php` y `php mp_smoketest.php` solo por CLI.

## Nginx (equivalente a `.htaccess`)

Si el hosting usa Nginx en lugar de Apache, denegá explícitamente:

```nginx
location ~ ^/(app|config|database|storage|install)(/|$) { deny all; }
location ~ ^/(cron|diagnostico|migrate_|mp_smoketest|seed_usuarios).*\.php$ { deny all; }
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
# Confiar en el proxy solo si controlás el edge:
# fastcgi_param HTTPS $http_x_forwarded_proto;
```

Tras el primer deploy, borrá del document root (si no usás el denegado): `install/`, `migrate_*.php`, `diagnostico.php`, `seed_usuarios.php`, `mp_smoketest.php`.

## Lo que NO es banco del sistema nacional

CVU/DEBIN/ECHEQ/API en Credimax son del **ledger privado** (entity 900). No interoperan con Coelsa/BCRA hasta integrar un PSP/banco aliado. La UI y legales deben mantener ese aviso.

## Veredicto honesto

**Operable al 100% como plataforma privada de crédito P2P + billetera interna con conciliación admin.**  
**No** “banco regulado listo para público masivo” sin los puntos legales/PSP de arriba.
