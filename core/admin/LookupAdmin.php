<?php
declare(strict_types=1);

namespace Cms;

/** JSON endpoints for the admin autocomplete fields. Signed-in administrator only (enforced by AdminApp); GET only; never cached. */
final class LookupAdmin
{
    private LookupService $service;

    public function __construct(private Database $db, private array $user)
    {
        $this->service = new LookupService($db);
    }

    /** @return int 0 when handled, otherwise 404 or 405 */
    public function handle(string $path, string $method): int
    {
        $source = substr($path, strlen('/admin/lookup/'));
        if (!in_array($source, ['content-parent', 'media-image'], true)) {
            return 404;
        }
        if ($method !== 'GET') {
            return 405;
        }
        $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        if ($source === 'content-parent') {
            $exclude = is_string($_GET['exclude'] ?? null) && ctype_digit($_GET['exclude']) && strlen($_GET['exclude']) < 10 ? (int) $_GET['exclude'] : null;
            $items = $this->service->contentParents($q, $exclude);
        } else {
            $items = $this->service->images($q);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return 0;
    }
}
