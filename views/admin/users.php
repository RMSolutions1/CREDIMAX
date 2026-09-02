<?php
/** @var array $users */
?>
<section class="page-head"><h1>Usuarios</h1></section>
<section class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Credimax</th><th>Nombre</th><th>Email</th><th>KYC</th><th>Saldo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= e($u['credimax_id']) ?></td>
          <td><?= e($u['first_name'].' '.$u['last_name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e(status_label($u['kyc_status'])) ?></td>
          <td><?= e(money($u['balance'] ?? 0)) ?></td>
          <td><?= e(status_label($u['status'])) ?></td>
          <td>
            <?php if ((int)$u['id'] !== auth_id()): ?>
            <form method="post" action="<?= e(url('/admin/users/'.$u['id'].'/toggle')) ?>">
              <?= csrf_field() ?>
              <button class="linkish" type="submit"><?= $u['status']==='active'?'Suspender':'Activar' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
