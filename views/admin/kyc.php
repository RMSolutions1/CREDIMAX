<?php
/** @var array $users */
/** @var array $docsByUser */
$docsByUser = $docsByUser ?? [];
?>
<section class="page-head"><h1>Revisión KYC</h1></section>
<section class="panel">
  <?php foreach ($users as $u): ?>
    <?php $docs = $docsByUser[(int) $u['id']] ?? []; ?>
    <div class="kyc-row">
      <div>
        <strong><?= e($u['credimax_id']) ?></strong> — <?= e($u['first_name'].' '.$u['last_name']) ?>
        <div class="muted"><?= e($u['email']) ?> · DNI <?= e($u['dni'] ?? '—') ?> · <?= e(status_label($u['kyc_status'])) ?></div>
        <?php if ($docs): ?>
          <div class="muted" style="margin-top:.5rem">
            Documentos:
            <?php
            $links = [];
            foreach ($docs as $doc) {
                $links[] = '<a href="' . e(url('/admin/kyc/' . (int) $u['id'] . '/doc/' . (int) $doc['id'])) . '" target="_blank" rel="noopener">'
                    . e((string) $doc['doc_type']) . '</a>';
            }
            echo implode(' · ', $links);
            ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if (in_array($u['kyc_status'], ['submitted', 'pending'], true)): ?>
      <form method="post" action="<?= e(url('/admin/kyc/'.$u['id'])) ?>" class="form inline-form">
        <?= csrf_field() ?>
        <input name="notes" placeholder="Notas">
        <button class="btn btn-accent" name="decision" value="approved" type="submit">Aprobar</button>
        <button class="btn" name="decision" value="rejected" type="submit">Rechazar</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
