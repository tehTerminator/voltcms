<?php
declare(strict_types=1);

namespace Cms;

/** Admin screens for code assets: reusable stylesheets and scripts that are linked to pages and articles. */
final class CodeAdmin
{
    private CodeAssetRepository $repo;
    private CodeAssetStore $store;
    private CodeAssetWriter $writer;

    public function __construct(private Database $db, private array $user)
    {
        $this->repo = new CodeAssetRepository($db);
        $this->store = new CodeAssetStore(CMS_ROOT . '/user');
        $this->writer = new CodeAssetWriter($db, $this->store, $this->repo);
    }

    /** @return int 0 when handled, otherwise 404 or 405 */
    public function handle(string $path, string $method): int
    {
        $parts = array_values(array_filter(explode('/', substr($path, strlen('/admin/code'))), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return $method === 'GET' ? $this->listPage() : 405;
        }
        if ($parts === ['new']) {
            return $method === 'GET' ? $this->form(null, null) : ($method === 'POST' ? $this->save(null) : 405);
        }
        $row = ctype_digit($parts[0]) && strlen($parts[0]) < 10 ? $this->repo->find((int) $parts[0]) : null;
        if ($row === null) {
            return 404;
        }
        if (count($parts) === 1) {
            return $method === 'GET' ? $this->form($row, null) : ($method === 'POST' ? $this->save($row) : 405);
        }
        if (count($parts) === 2 && $parts[1] === 'delete') {
            return $method === 'POST' ? $this->delete($row) : 405;
        }
        if (count($parts) === 2 && $parts[1] === 'restore') {
            return $method === 'POST' ? $this->restore($row) : 405;
        }
        return 404;
    }

    private function listPage(): int
    {
        $kind = is_string($_GET['kind'] ?? null) && isset(CodeAssetStore::KINDS[$_GET['kind']]) ? $_GET['kind'] : null;
        Response::html(View::render('code_list', [
            'title' => 'Code', 'nav' => true, 'rows' => $this->repo->all($kind), 'kind' => $kind ?? '', 'usage' => $this->repo->usageCounts(),
            'kinds' => CodeAssetStore::KINDS, 'integrity' => $this->repo->integrity($this->store),
        ]));
        return 0;
    }

    private function form(?array $row, ?array $values, array $errors = [], int $status = 200): int
    {
        $kind = $row['kind'] ?? (is_string($_GET['kind'] ?? null) && isset(CodeAssetStore::KINDS[$_GET['kind']]) ? $_GET['kind'] : 'css');
        if ($values === null) {
            $code = $row === null ? '' : $this->store->read($row['kind'], $row['file']);
            if ($row !== null && $code === null) {
                $errors[] = 'The file for this asset is missing on the server. Saving will create it again from what you enter here.';
            }
            $values = $row === null
                ? ['name' => '', 'description' => '', 'load_order' => '100', 'code' => '']
                : ['name' => $row['name'], 'description' => (string) $row['description'], 'load_order' => (string) $row['load_order'], 'code' => $code ?? ''];
        }
        Response::html(View::render('code_form', [
            'title' => $row === null ? 'New ' . strtolower(CodeAssetStore::KINDS[$kind]) : 'Edit: ' . $row['name'], 'nav' => true, 'item' => $row, 'kind' => $kind,
            'v' => $values, 'errors' => $errors, 'usedBy' => $row === null ? [] : $this->repo->usedBy((int) $row['id']),
            'versions' => $row === null ? [] : $this->store->versions((int) $row['id'], $row['kind']), 'url' => $row === null ? '' : CodeAssetRepository::url($row),
        ]), $status);
        return 0;
    }

    private function save(?array $row): int
    {
        $kind = $row['kind'] ?? Request::post('kind');
        [$errors, $clean] = $this->writer->validate($kind, Request::post('name'), Request::post('description'), Request::post('load_order'), Request::post('code'), $row === null ? null : (int) $row['id']);
        if ($errors === []) {
            try {
                $id = $row === null ? $this->writer->create($clean) : (int) $row['id'];
                if ($row !== null) {
                    $this->writer->update($id, $clean);
                }
                Session::flash('success', $row === null ? 'Created. Link it to a page or article from that item\'s editor.' : 'Saved.');
                Response::redirect('/admin/code/' . $id);
                return 0;
            } catch (\PDOException $e) {
                $errors[] = 'The database rejected the change (is the name already used?).';
                Logger::error('Code asset save failed: ' . $e->getMessage());
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
                Logger::error('Code asset save failed: ' . $e->getMessage());
            }
        }
        $_GET['kind'] = $kind;
        return $this->form($row, ['name' => Request::post('name'), 'description' => Request::post('description'), 'load_order' => Request::post('load_order'), 'code' => Request::post('code')], $errors, 422);
    }

    private function delete(array $row): int
    {
        $this->writer->delete((int) $row['id']);
        Session::flash('success', 'Deleted and unlinked from all pages. The file was archived in user/backups/deleted/.');
        Response::redirect('/admin/code');
        return 0;
    }

    private function restore(array $row): int
    {
        $error = $this->writer->restoreVersion((int) $row['id'], Request::post('version'));
        Session::flash($error === null ? 'success' : 'danger', $error ?? 'Version restored. The code you replaced is now in the history.');
        Response::redirect('/admin/code/' . (int) $row['id']);
        return 0;
    }
}
