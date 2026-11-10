<?php /** Site search box (GET /search). Optional variable: $q, the current query. */ ?>
<form class="d-flex" role="search" action="<?= e(url('/search')) ?>" method="get">
  <input class="form-control form-control-sm me-2" type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="Search" aria-label="Search the site" minlength="2" maxlength="100" required>
  <button class="btn btn-outline-secondary btn-sm" type="submit">Search</button>
</form>
