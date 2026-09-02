# Credimax

Plataforma web de **créditos P2P** con **billetera**, **QR**, **tesorería** e identidad `CMX-XXXXXXXX`.

Stack: **PHP 8+ + MySQL** (sin Composer). Compatible con hosting compartido / XAMPP.

**Repositorio:** [github.com/RMSolutions1/CREDIMAX](https://github.com/RMSolutions1/CREDIMAX)

## Clonar e instalar

```bash
git clone https://github.com/RMSolutions1/CREDIMAX.git
cd CREDIMAX
copy config\config.sample.php config\config.php   # Windows
# cp config/config.sample.php config/config.php   # Linux/macOS
```

1. Crear base MySQL e importar `database/schema.sql` (o usar `/install/` en local).
2. Completar `config/config.php` (secretos, DB, `app_url`).
3. `php migrate_production.php` y `php migrate_mercadopago.php` si aplica.
4. `php diagnostico.php` → debe terminar **sin fallas**.
5. Usuarios demo (solo local): `php seed_usuarios.php`

> `config/config.php` **no se versiona** (está en `.gitignore`). Cada entorno tiene el suyo.

## Documentación operativa

- Checklist de producción: [`PRODUCTION.md`](PRODUCTION.md)
- Tasas TNA/TEA/CFT: `/tasas` y simulador
- Cumplimiento: `/legales/cumplimiento`
- API ledger interno: `/api/docs`

## Instalación local (XAMPP)

1. Junction/symlink a `htdocs/credimax` o servir esta carpeta.
2. Crear DB `credimax` e importar `database/schema.sql` (o usar `/install/`).
3. `config/config.php` ya viene en modo `local` para desarrollo.
4. Abrir `http://localhost/credimax/`

## Producción (resumen)

1. Copiar `config/config.sample.php` → `config/config.php`
2. Completar secretos (`jwt_secret`, `cron_key`, DB, HTTPS)
3. `php migrate_production.php` si actualizás una instalación previa
4. Borrar `/install`, `migrate_*.php`
5. Cron: `php cron.php`
6. Seguir [`PRODUCTION.md`](PRODUCTION.md)

## Flujo operativo real (ledger + conciliación)

1. Usuario informa **depósito** → Admin confirma en **Fondos**
2. Solicitante publica crédito (KYC aprobado) → Inversores fondean **manual**
3. Desembolso 100% del monto pedido (comisión capitalizada en cuotas)
4. Pagos de cuotas → prorrateo a inversores
5. **Retiro** a CBU/alias → Admin marca pagado tras transferencia bancaria

El auto-fondeo está **desactivado** (solo alertas) por alineación BCRA PSCPP.

La **comisión de originación** viaja financiada dentro de la cuota (campo `fee_portion`) y se
acredita a la tesorería de Credimax, no a los prestamistas. El resto de la cuota se reparte
entre los fondeadores por resto mayor, así la suma repartida es exactamente lo cobrado.

## Mercado Pago (billetera como sub-cuenta)

Una sola **cuenta madre** de Mercado Pago concentra el dinero real. Cada billetera Credimax
es una **sub-cuenta virtual** del ledger, espejada contra los pagos reales de esa cuenta.

| Flujo | Cómo funciona |
|-------|---------------|
| Carga de saldo | Preferencia de Checkout Pro con `external_reference` propia → webhook → acreditación idempotente |
| Cobro por link/QR | Preferencia a favor de la sub-cuenta; se acredita neto de comisión de plataforma |
| Devolución | Refund real por API, con débito previo en el ledger |
| Retiro | Orden en `mp_payouts` que el admin ejecuta desde la cuenta madre y concilia |
| Vinculación | OAuth con PKCE; los tokens se guardan cifrados con AES-256-GCM |

Reglas que sostienen la integración:

- El cuerpo del webhook **nunca se cree**: siempre se relee el pago desde la API.
- Cada evento se registra con clave única, así los reintentos no acreditan dos veces.
- La firma `x-signature` se valida por HMAC-SHA256 con ventana antireplay.
- Los secretos (`access_token`, `client_secret`, `webhook_secret`) se guardan cifrados en `settings`.

### Puesta en marcha

1. `php migrate_mercadopago.php` (crea tablas y cifra secretos preexistentes).
2. Cargar credenciales en `/admin/mercadopago`.
3. En el panel de Mercado Pago, apuntar el webhook a `https://TU-DOMINIO/webhooks/mercadopago`
   con el tópico **payment**, y copiar la **clave secreta** que genera el panel.
4. `php diagnostico.php` debe terminar sin fallas.
5. `php mp_smoketest.php` crea una preferencia real de prueba sin tocar el ledger.

`app_url` de producción: **`https://unipagos.online`** (HTTPS obligatorio).
Sin eso Mercado Pago rechaza `notification_url` y `auto_return`,
y la acreditación queda dependiendo de la conciliación manual.

Webhook: `https://unipagos.online/webhooks/mercadopago`  
OAuth: `https://unipagos.online/wallet/mp/vincular/callback`

## Herramientas de verificación

| Script | Para qué |
|--------|----------|
| `diagnostico.php` | Entorno, esquema, integridad del ledger, salud de Mercado Pago, rutas y vistas |
| `mp_smoketest.php` | Prueba de humo contra la API real (no afecta saldos) |
| `migrate_mercadopago.php` | Migración idempotente de la integración |

Borralos en producción una vez verificado el despliegue.

## Usuarios de prueba (local)

Crear o actualizar: `php seed_usuarios.php`

Contraseña de todas: `Demo1234!`

| Rol | Email | ID | Saldo inicial |
|-----|-------|----|---------------|
| Administrador | `admin@credimax.test` | CMX-ADMIN01 | $0 |
| Inversora (KYC ok) | `inversor@credimax.test` | CMX-LENDER01 | $1.500.000 |
| Solicitante (KYC ok) | `solicitante@credimax.test` | CMX-BORROW01 | $120.000 |
| PyME (KYC ok) | `pyme@credimax.test` | CMX-PYME0001 | $350.000 |
| Usuario nuevo (KYC pendiente) | `nuevo@credimax.test` | CMX-NUEVO001 | $0 |

**No uses estas cuentas en producción.**

## Seguridad incluida

CSRF, password hash, login lock, reset password, JWT secret, OTP hasheado, TOTP opcional, CSP/HSTS, audit logs, install lock.

## Mantener el repo actualizado

```bash
git pull origin main
php migrate_production.php
php migrate_mercadopago.php
php diagnostico.php
```

No commitear `config/config.php`, logs ni uploads KYC.
