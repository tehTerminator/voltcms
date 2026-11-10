<?php
/** Site footer. Prints the footer text from Site settings ({year} becomes the current year), or a copyright line with the site name. */
$footer = trim((string) ($site['footer'] ?? ''));
$text = $footer !== '' ? str_replace('{year}', gmdate('Y'), $footer) : '© ' . gmdate('Y') . ' ' . $site['name'];
?>
<footer class="container py-4 text-secondary small border-top"><?= e($text) ?></footer>
