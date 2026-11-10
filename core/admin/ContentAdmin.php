<?php
declare(strict_types=1);

namespace Cms;

/** Admin screens for content: list, create, edit, preview, trash, bulk actions, revisions, integrity report. */
final class ContentAdmin
{
    private const PER_PAGE = 20;
    private const FORM_FIELDS = ['type', 'parent_id', 'slug', 'title', 'status', 'visibility', 'excerpt', 'meta_description', 'seo_title', 'og_image_media_id', 'tags', 'body'];

    private ContentRepository $repo;
    private ContentStore $store;
    private ContentWriter $writer;

    public function __construct(private Database $db, private array $user)
    {
        $this->repo = new ContentRepository($db);
        $this->store = new ContentStore(CMS_ROOT . '/user');
        $this->writer = new ContentWriter($db, $this->store, new Sanitizer(), $this->repo);
    }

    /** @return int 0 when handled, otherwise the HTTP status the caller should report (404 or 405) */
    public function handle(string $path, string $method): int
    {
        $rest = trim(substr($path, strlen('/admin/content')), '/');
        $parts = $rest === '' ? [] : explode('/', $rest);
        $n = count($parts);
        $id = $n >= 1 && ctype_digit($parts[0]) && strlen($parts[0]) < 10 ? (int) $parts[0] : null;

        if ($n === 0) {
            return $this->only($method, 'GET') ?: $this->listPage();
        }
        if ($parts[0] === 'new' && $n === 1) {
            return $method === 'POST' ? $this->save(null) : ($method === 'GET' ? $this->form(null) : 405);
        }
        if ($parts[0] === 'integrity' && $n === 1) {
            return $this->only($method, 'GET') ?: $this->integrity();
        }
        if ($parts[0] === 'bulk' && $n === 1) {
            return $this->only($method, 'POST') ?: $this->bulk();
        }
        if ($id === null) {
            return 404;
        }
        $row = $this->repo->find($id);
        if ($row === null) {
            return 404;
        }
        if ($n === 1) {
            return $method === 'POST' ? $this->save($row) : ($method === 'GET' ? $this->form($row) : 405);
        }
        $action = $parts[1];
        if ($n === 2) {
            switch ($action) {
                case 'preview':
                    return $this->only($method, 'GET') ?: $this->preview($row);
                case 'revisions':
                    return $this->only($method, 'GET') ?: $this->revisions($row);
                case 'trash':
                case 'restore':
                case 'purge':
                case 'move':
                    return $this->only($method, 'POST') ?: $this->action($row, $action);
            }
        }
        if ($n === 4 && $action === 'revisions' && ctype_digit($parts[2]) && $parts[3] === 'restore') {
            return $this->only($method, 'POST') ?: $this->restoreRevision($row, (int) $parts[2]);
        }
        return 404;
    }

    /** @return int 0 when the method matches, else 405 */
    private function only(string $method, string $expected): int
    {
        return $method === $expected ? 0 : 405;
    }

    // ---- list ---------------------------------------------------------------------------------

    private function listPage(): int
    {
        $filters = [
            'q' => $this->query('q'),
            'type' => $this->query('type'),
            'status' => $this->query('status'),
        ];
        $p = $_GET['page'] ?? '1';
        $page = is_string($p) && ctype_digit($p) && strlen($p) < 6 ? max(1, (int) $p) : 1;
        $outline = $filters['type'] === 'book' && $filters['q'] === '' && $filters['status'] !== 'trash';
        if ($outline) {
            $rows = $this->repo->bookOutline();
            if (in_array($filters['status'], ['draft', 'published'], true)) {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === $filters['status']));
            }
            $total = count($rows);
        } else {
            $result = $this->repo->adminList($filters, $page, self::PER_PAGE);
            $rows = $result['rows'];
            $total = $result['total'];
        }
        $this->page('content_list', [
            'title' => 'Content', 'nav' => true, 'rows' => $rows, 'total' => $total, 'filters' => $filters,
            'page' => $page, 'pages' => $outline ? 1 : max(1, (int) ceil($total / self::PER_PAGE)),
            'outline' => $outline, 'counts' => $this->repo->counts(), 'types' => ContentRepository::TYPES,
        ]);
        return 0;
    }

    private function query(string $key): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    // ---- create / edit ------------------------------------------------------------------------

    private function form(?array $row, array $errors = [], ?array $values = null, int $status = 200): int
    {
        if ($values === null) {
            if ($row === null) {
                $type = $this->query('type');
                $values = ['type' => isset(ContentRepository::TYPES[$type]) ? $type : 'article', 'parent_id' => '', 'slug' => '', 'title' => '',
                    'status' => 'draft', 'visibility' => 'public', 'excerpt' => '', 'meta_description' => '', 'noindex' => '0',
                    'seo_title' => '', 'og_image_media_id' => '', 'tags' => '', 'asset_ids' => [], 'body' => ''];
            } else {
                $body = $this->store->read((int) $row['id']);
                $values = [
                    'type' => $row['type'], 'parent_id' => (string) ($row['parent_id'] ?? ''), 'slug' => $row['slug'], 'title' => $row['title'],
                    'status' => $row['status'], 'visibility' => $row['visibility'], 'excerpt' => (string) $row['excerpt'],
                    'meta_description' => (string) $row['meta_description'], 'noindex' => (string) $row['noindex'],
                    'seo_title' => (string) $row['seo_title'], 'og_image_media_id' => (string) ($row['og_image_media_id'] ?? ''),
                    'tags' => implode(', ', array_column((new TagRepository($this->db))->forContent((int) $row['id']), 'name')),
                    'asset_ids' => (new CodeAssetRepository($this->db))->linkedIds((int) $row['id']), 'body' => $body ?? '',
                ];
                if ($body === null) {
                    $errors[] = 'The body file for this item is missing on the server. Saving will create it again from what you enter here.';
                }
            }
        }
        $this->page('content_form', [
            'title' => $row === null ? 'New content' : 'Edit: ' . $row['title'], 'nav' => true, 'item' => $row, 'v' => $values,
            'errors' => $errors, 'types' => ContentRepository::TYPES,
            'hasPassword' => $row !== null && $row['view_password_hash'] !== null,
            'assets' => (new CodeAssetRepository($this->db))->all(),
        ] + $this->pickerLabels($values), $status);
        return 0;
    }

    /** Labels (and thumbnail) of the currently chosen parent and social image, for the autocomplete fields. */
    private function pickerLabels(array $values): array
    {
        $parent = ctype_digit((string) $values['parent_id']) && (int) $values['parent_id'] > 0 ? $this->repo->find((int) $values['parent_id']) : null;
        $og = ctype_digit((string) $values['og_image_media_id']) && (int) $values['og_image_media_id'] > 0 ? (new MediaRepository($this->db))->find((int) $values['og_image_media_id']) : null;
        return [
            'parentLabel' => $parent === null ? '' : (string) $parent['title'],
            'ogLabel' => $og === null ? '' : (string) $og['original_name'],
            'ogThumb' => $og === null ? '' : MediaRepository::thumbUrl($og),
        ];
    }

    private function save(?array $row): int
    {
        [$errors, $clean] = $this->writer->validate($_POST, $row);
        if ($errors === []) {
            try {
                if ($row === null) {
                    $id = $this->writer->create($clean);
                } else {
                    $id = (int) $row['id'];
                    $this->writer->update($id, $clean, (int) $this->user['id']);
                }
                Session::flash('success', $row === null ? 'Created.' : 'Saved.');
                Response::redirect('/admin/content/' . $id);
                return 0;
            } catch (\PDOException $e) {
                $errors[] = ($e->errorInfo[1] ?? 0) === 1062 ? 'That slug was just taken by another item. Choose a different one.' : 'The database rejected the change.';
                Logger::error('Content save failed: ' . $e->getMessage());
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
                Logger::error('Content save failed: ' . $e->getMessage());
            }
        }
        $values = [];
        foreach (self::FORM_FIELDS as $f) {
            $values[$f] = Request::post($f);
        }
        $values['noindex'] = Request::post('noindex') === '1' ? '1' : '0';
        $values['asset_ids'] = array_map('intval', array_filter(is_array($_POST['asset_ids'] ?? null) ? $_POST['asset_ids'] : [], static fn ($v): bool => is_string($v) && ctype_digit($v)));
        return $this->form($row, $errors, $values, 422);
    }

    // ---- preview ------------------------------------------------------------------------------

    private function preview(array $row): int
    {
        $body = $this->store->read((int) $row['id']);
        if ($body === null) {
            Session::flash('danger', 'The body file is missing, so there is nothing to preview.');
            Response::redirect('/admin/content/' . (int) $row['id']);
            return 0;
        }
        (new PublicApp())->preview($row, $body);
        return 0;
    }

    // ---- actions (POST) -----------------------------------------------------------------------

    private function action(array $row, string $action): int
    {
        $id = (int) $row['id'];
        $error = null;
        $back = '/admin/content';
        switch ($action) {
            case 'trash':
                $error = $this->writer->trash($id);
                $ok = 'Moved to the trash. You can restore it from Trash.';
                break;
            case 'restore':
                $error = $this->writer->restore($id);
                $ok = 'Restored from the trash.';
                $back = '/admin/content?status=trash';
                break;
            case 'purge':
                $error = $this->writer->purge($id);
                $ok = 'Deleted permanently. The files were archived in user/backups/deleted/.';
                $back = '/admin/content?status=trash';
                break;
            default: // move
                $this->writer->move($id, Request::post('dir'));
                $ok = 'Order updated.';
                $back = '/admin/content?type=book';
        }
        Session::flash($error === null ? 'success' : 'danger', $error ?? $ok);
        Response::redirect($back);
        return 0;
    }

    private function bulk(): int
    {
        $ids = [];
        $posted = $_POST['ids'] ?? [];
        foreach (is_array($posted) ? $posted : [] as $v) {
            if (is_string($v) && ctype_digit($v)) {
                $ids[] = (int) $v;
            }
        }
        $ids = array_slice(array_unique($ids), 0, 200);
        $action = Request::post('bulk_action');
        if ($ids === [] || !in_array($action, ['publish', 'draft', 'trash'], true)) {
            Session::flash('warning', 'Select at least one item and choose an action.');
            Response::redirect('/admin/content');
            return 0;
        }
        if ($action === 'trash') {
            $done = 0;
            $problems = [];
            foreach ($ids as $id) {
                $err = $this->writer->trash($id);
                $err === null ? $done++ : $problems[] = $err;
            }
            Session::flash($problems === [] ? 'success' : 'warning', $done . ' moved to the trash.' . ($problems === [] ? '' : ' ' . implode(' ', $problems)));
        } else {
            $n = $this->writer->setStatus($ids, $action === 'publish' ? 'published' : 'draft');
            Session::flash('success', $n . ' updated.');
        }
        Response::redirect('/admin/content');
        return 0;
    }

    // ---- revisions and integrity --------------------------------------------------------------

    private function revisions(array $row): int
    {
        $this->page('content_revisions', [
            'title' => 'Revisions: ' . $row['title'], 'nav' => true, 'item' => $row,
            'revisions' => $this->repo->revisions((int) $row['id']),
        ]);
        return 0;
    }

    private function restoreRevision(array $row, int $revisionId): int
    {
        $error = $this->writer->restoreRevision((int) $row['id'], $revisionId, (int) $this->user['id']);
        Session::flash($error === null ? 'success' : 'danger', $error ?? 'Revision restored. The version you replaced is now in the history.');
        Response::redirect('/admin/content/' . (int) $row['id'] . '/revisions');
        return 0;
    }

    private function integrity(): int
    {
        $this->page('content_integrity', [
            'title' => 'Content integrity', 'nav' => true, 'report' => $this->repo->integrity($this->store),
            'mediaReport' => (new MediaRepository($this->db))->integrity(CMS_ROOT . '/user/uploads'),
            'codeReport' => (new CodeAssetRepository($this->db))->integrity(new CodeAssetStore(CMS_ROOT . '/user')),
        ]);
        return 0;
    }

    private function page(string $view, array $vars, int $status = 200): void
    {
        Response::html(View::render($view, $vars), $status);
    }
}
