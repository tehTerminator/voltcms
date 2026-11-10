<?php
/**
 * Newer/Older pager. Pass: page (int), pages (int), base (application path, default '/'), query (extra query parameters, default []).
 * Page 1 has no ?page= parameter, so the first page keeps one address.
 */
$base = $base ?? '/';
$query = $query ?? [];
if ($pages > 1):
    $href = static function (int $n) use ($base, $query): string {
        $params = $query + ($n > 1 ? ['page' => $n] : []);
        return url($base) . ($params === [] ? '' : '?' . http_build_query($params));
    };
?>
<nav aria-label="Pagination"><ul class="pagination">
  <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($href(max(1, $page - 1))) ?>" rel="prev">Newer</a></li>
  <li class="page-item disabled"><span class="page-link">Page <?= e($page) ?> of <?= e($pages) ?></span></li>
  <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($href(min($pages, $page + 1))) ?>" rel="next">Older</a></li>
</ul></nav>
<?php endif; ?>
