<?php /** @var array $stats */ ?>
<section class="section">
  <h1>Estadísticas del sistema</h1>
  <p class="section-lead">Indicadores agregados de la plataforma. Se actualizan con la operatoria real.</p>

  <div class="stat-grid">
    <div class="stat"><span>Monto en créditos</span><strong><?= e(money($stats['volume_credits'])) ?></strong></div>
    <div class="stat"><span>Préstamos otorgados</span><strong><?= e(number_format((int)$stats['loans_granted'], 0, ',', '.')) ?></strong></div>
    <div class="stat"><span>Intereses cobrados</span><strong><?= e(money($stats['interest_paid'])) ?></strong></div>
    <div class="stat"><span>Solicitudes abiertas</span><strong><?= (int)$stats['open_requests'] ?></strong></div>
    <div class="stat"><span>Inversores activos</span><strong><?= (int)$stats['investors'] ?></strong></div>
    <div class="stat"><span>Solicitantes</span><strong><?= (int)$stats['borrowers'] ?></strong></div>
    <div class="stat"><span>Cuotas en mora / pendientes</span><strong><?= e(number_format((float)$stats['overdue_ratio'], 1)) ?>%</strong></div>
  </div>

  <section class="panel" style="margin-top:1.5rem">
    <h2>Distribución por perfil de riesgo</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Perfil</th><th>Etiqueta</th><th>Créditos</th><th>Monto</th></tr></thead>
        <tbody>
        <?php foreach ($stats['by_band'] as $row):
          $b = (string)$row['band'];
        ?>
          <tr>
            <td><strong><?= e($b) ?></strong></td>
            <td><?= e(\App\Services\ScoringService::bandLabel($b)) ?></td>
            <td><?= (int)$row['c'] ?></td>
            <td><?= e(money($row['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($stats['by_band'])): ?>
          <tr><td colspan="4" class="muted">Aún no hay datos para mostrar.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>
