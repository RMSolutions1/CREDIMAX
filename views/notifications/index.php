<?php
/** @var array $rows */
?>
<section class="page-head"><h1>Notificaciones</h1></section>
<section class="panel">
  <div class="list">
    <?php foreach ($rows as $r): ?>
      <div class="list-item">
        <div>
          <strong><?= e($r['title']) ?></strong>
          <div class="muted"><?= e($r['body']) ?></div>
          <div class="muted"><?= e($r['created_at']) ?></div>
        </div>
        <?php if ($r['link']): ?><a href="<?= e($r['link']) ?>">Abrir</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="muted">Sin notificaciones.</p><?php endif; ?>
  </div>
</section>
