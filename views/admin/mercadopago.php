<?php
/** @var bool $configured */
/** @var bool $enabled */
/** @var bool $sandbox */
/** @var bool $hasWebhookSecret */
/** @var bool $hasOauth */
/** @var string $siteId */
/** @var array|null $account */
/** @var string|null $accountError */
/** @var string $webhookUrl */
/** @var string $redirectUri */
/** @var array $settings */
/** @var array $stats */
/** @var array $recentPayments */
/** @var array $events */
/** @var array $payouts */

$diff = round($stats['credited_total'] - $stats['customer_ledger'], 2);
?>
<section class="page-head">
  <div>
    <h1>Mercado Pago</h1>
    <p class="muted">
      Cuenta madre de la billetera Credimax. Cada wallet es una sub-cuenta del ledger
      espejada contra los pagos reales de esta cuenta.
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/admin/funds')) ?>">Tesorería</a>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><span>Estado</span><strong><?= $enabled ? 'Activo' : ($configured ? 'Configurado, apagado' : 'Sin configurar') ?></strong></div>
  <div class="stat"><span>Entorno</span><strong><?= $configured ? ($sandbox ? 'Pruebas' : 'Producción') : '—' ?></strong></div>
  <div class="stat"><span>Acreditado por MP</span><strong><?= e(money($stats['credited_total'])) ?></strong></div>
  <div class="stat"><span>Saldo de clientes</span><strong><?= e(money($stats['customer_ledger'])) ?></strong></div>
  <div class="stat"><span>Aprobados sin acreditar</span><strong><?= (int) $stats['approved_uncredited'] ?></strong></div>
  <div class="stat"><span>Retiros por pagar</span><strong><?= e(money($stats['pending_payouts'])) ?></strong></div>
  <div class="stat"><span>Webhooks con error</span><strong><?= (int) $stats['failed_events'] ?></strong></div>
  <div class="stat"><span>Cuentas vinculadas</span><strong><?= (int) $stats['linked_accounts'] ?></strong></div>
</div>

<?php if ((int) $stats['approved_uncredited'] > 0): ?>
  <section class="panel">
    <h2>Hay pagos aprobados sin acreditar</h2>
    <p class="muted">
      <?= (int) $stats['approved_uncredited'] ?> pago(s) aprobados en Mercado Pago no tienen
      contrapartida en el ledger. Corré la conciliación para repararlos.
    </p>
  </section>
<?php endif; ?>

<div class="grid-2">
  <section class="panel">
    <h2>Credenciales</h2>
    <p class="muted">
      Se guardan en la base y tienen prioridad sobre <code>config/config.php</code>.
      Los campos secretos se muestran enmascarados: dejalos vacíos para no modificarlos.
      Obtenelas en <strong>mercadopago.com.ar/developers/panel/app</strong>.
    </p>
    <form method="post" action="<?= e(url('/admin/mercadopago')) ?>" class="form">
      <?= csrf_field() ?>

      <label>
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Habilitar cobros y cargas con Mercado Pago
      </label>

      <label>País (site_id)</label>
      <input name="site_id" value="<?= e($settings['site_id'] ?? $siteId) ?>" placeholder="MLA">

      <label>Access token <?= $configured ? '<span class="muted">(cargado)</span>' : '' ?></label>
      <input name="access_token" autocomplete="off" placeholder="<?= e($settings['access_token'] ?? 'APP_USR-...') ?>">

      <label>Public key</label>
      <input name="public_key" autocomplete="off" value="<?= e($settings['public_key'] ?? '') ?>">

      <label>Client ID <span class="muted">(solo para vincular cuentas)</span></label>
      <input name="client_id" autocomplete="off" value="<?= e($settings['client_id'] ?? '') ?>">

      <label>Client secret <?= $hasOauth ? '<span class="muted">(cargado)</span>' : '' ?></label>
      <input name="client_secret" autocomplete="off" placeholder="<?= e($settings['client_secret'] ?? '') ?>">

      <label>Clave secreta de webhooks <?= $hasWebhookSecret ? '<span class="muted">(cargada)</span>' : '' ?></label>
      <input name="webhook_secret" autocomplete="off" placeholder="<?= e($settings['webhook_secret'] ?? '') ?>">

      <button class="btn btn-accent" type="submit">Guardar</button>
    </form>
  </section>

  <section class="panel">
    <h2>Configuración en Mercado Pago</h2>
    <p class="muted">Registrá estas URLs en el panel de tu aplicación:</p>
    <p><strong>Webhook (tópico <code>payment</code>)</strong><br><code><?= e($webhookUrl) ?></code></p>
    <p style="margin-top:10px"><strong>URL de redirección OAuth</strong><br><code><?= e($redirectUri) ?></code></p>

    <h3 style="margin-top:20px">Cuenta madre conectada</h3>
    <?php if ($account): ?>
      <p class="muted">
        <?= e((string) ($account['nickname'] ?? '')) ?>
        · ID <code><?= e((string) ($account['id'] ?? '')) ?></code>
        · <?= e((string) ($account['email'] ?? '')) ?>
        · <?= e((string) ($account['site_id'] ?? '')) ?>
      </p>
    <?php elseif ($accountError): ?>
      <p class="muted">No se pudo consultar la cuenta: <?= e($accountError) ?></p>
    <?php else: ?>
      <p class="muted">Cargá el access token para verificar la conexión.</p>
    <?php endif; ?>

    <h3 style="margin-top:20px">Conciliación</h3>
    <p class="muted">
      Compara los pagos reales contra el ledger y acredita los que hayan quedado sin procesar.
      Diferencia actual acreditado − saldo de clientes: <strong><?= e(money($diff)) ?></strong>
      (esperable ≠ 0 por retiros, préstamos y comisiones).
    </p>
    <form method="post" action="<?= e(url('/admin/mercadopago/conciliar')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Días hacia atrás</label>
      <input name="days" value="3" inputmode="numeric">
      <button class="btn btn-accent" type="submit">Conciliar ahora</button>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Retiros por transferir (<?= count($payouts) ?>)</h2>
  <p class="muted">
    Mercado Pago no permite transferir a terceros por API. Ejecutá cada transferencia desde
    tu cuenta y registrá acá el número de operación para dejar la trazabilidad.
  </p>
  <p><a class="btn" href="<?= e(url('/admin/mercadopago/payouts.csv')) ?>">Descargar CSV</a></p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Usuario</th><th>Destino</th><th>Titular</th><th>Monto</th><th>Registrar</th></tr></thead>
      <tbody>
      <?php foreach ($payouts as $p): ?>
        <tr>
          <td><?= (int) $p['id'] ?></td>
          <td><?= e((string) $p['credimax_id']) ?></td>
          <td><code><?= e((string) $p['destination']) ?></code> <span class="muted"><?= e((string) $p['destination_type']) ?></span></td>
          <td><?= e((string) ($p['holder'] ?? '—')) ?></td>
          <td><?= e(money($p['amount'])) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/mercadopago/payout/' . $p['id'])) ?>">
              <?= csrf_field() ?>
              <input name="operation_id" placeholder="Nº operación MP" required>
              <button class="btn" type="submit">Marcar transferido</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payouts): ?><tr><td colspan="6" class="muted">No hay retiros pendientes.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Últimos pagos</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Fecha</th><th>Pago MP</th><th>Usuario</th><th>Tipo</th><th>Monto</th><th>Estado</th><th>Acreditado</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($recentPayments as $p): ?>
        <tr>
          <td><?= e($p['created_at']) ?></td>
          <td><code><?= e((string) ($p['mp_payment_id'] ?? '—')) ?></code></td>
          <td><?= e((string) ($p['credimax_id'] ?? '—')) ?></td>
          <td><?= e((string) $p['kind']) ?></td>
          <td><?= e(money($p['amount'])) ?></td>
          <td><?= e((string) $p['status']) ?></td>
          <td><?= (int) $p['credited'] === 1 ? 'Sí' : 'No' ?></td>
          <td>
            <?php if (!empty($p['mp_payment_id'])): ?>
              <form method="post" action="<?= e(url('/admin/mercadopago/sync/' . $p['mp_payment_id'])) ?>" style="display:inline">
                <?= csrf_field() ?>
                <button class="btn" type="submit">Resincronizar</button>
              </form>
            <?php endif; ?>
            <?php if ((int) $p['credited'] === 1 && $p['status'] === 'approved' && (float) $p['refunded_amount'] < (float) $p['amount']): ?>
              <form method="post" action="<?= e(url('/admin/mercadopago/refund/' . $p['id'])) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input name="amount" placeholder="Total" size="8">
                <button class="btn" type="submit">Devolver</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentPayments): ?><tr><td colspan="8" class="muted">Sin pagos registrados.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Notificaciones recibidas</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Fecha</th><th>Tópico</th><th>Acción</th><th>Recurso</th><th>Firma</th><th>Procesado</th><th>Resultado</th></tr></thead>
      <tbody>
      <?php foreach ($events as $ev): ?>
        <tr>
          <td><?= e($ev['created_at']) ?></td>
          <td><?= e((string) $ev['type']) ?></td>
          <td><?= e((string) ($ev['action'] ?? '—')) ?></td>
          <td><code><?= e((string) $ev['data_id']) ?></code></td>
          <td><?= (int) $ev['signature_valid'] === 1 ? 'OK' : 'Inválida' ?></td>
          <td><?= (int) $ev['processed'] === 1 ? 'Sí' : 'No' ?></td>
          <td><?= e((string) ($ev['error'] ?? $ev['result'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$events): ?><tr><td colspan="7" class="muted">Todavía no llegaron notificaciones.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
