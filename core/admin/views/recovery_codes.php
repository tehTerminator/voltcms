<h1 class="h3 mb-4">Two-factor is on</h1>
<div class="alert alert-warning" role="alert"><strong>Save these recovery codes now.</strong> They are shown only once. Each code works one time if you lose your phone.</div>
<div class="card shadow-sm"><div class="card-body">
  <ul class="list-unstyled font-monospace fs-5 mb-0 user-select-all">
    <?php foreach ($codes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
  </ul>
</div></div>
<p class="mt-3"><a class="btn btn-primary" href="<?= e(url('/admin/security')) ?>">I have saved them</a></p>
