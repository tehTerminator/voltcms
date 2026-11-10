<?php
declare(strict_types=1);

namespace Cms;

/** Admin screen for tags: list with usage, rename, delete. Tags are assigned in the content editor. */
final class TagAdmin
{
    private const PER_PAGE = 50;

    private TagRepository $repo;
    private TagWriter $writer;

    public function __construct(private Database $db, private array $user)
    {
        $this->repo = new TagRepository($db);
        $this->writer = new TagWriter($db);
    }

    /** @return int 0 when handled, otherwise 404 or 405 */
    public function handle(string $path, string $method): int
    {
        $parts = array_values(array_filter(explode('/', substr($path, strlen('/admin/tags'))), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return $method === 'GET' ? $this->listPage() : 405;
        }
        $tag = ctype_digit($parts[0]) && strlen($parts[0]) < 10 ? $this->repo->find((int) $parts[0]) : null;
        if ($tag === null) {
            return 404;
        }
        if (count($parts) === 1) {
            return $method === 'POST' ? $this->rename($tag) : 405;
        }
        if (count($parts) === 2 && $parts[1] === 'delete') {
            return $method === 'POST' ? $this->delete($tag) : 405;
        }
        return 404;
    }

    private function listPage(): int
    {
        $p = $_GET['page'] ?? '1';
        $page = is_string($p) && ctype_digit($p) && strlen($p) < 6 ? max(1, (int) $p) : 1;
        $result = $this->repo->adminList($page, self::PER_PAGE);
        Response::html(View::render('tags', [
            'title' => 'Tags', 'nav' => true, 'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
        ]));
        return 0;
    }

    private function rename(array $tag): int
    {
        $error = $this->writer->rename((int) $tag['id'], Request::post('name'));
        Session::flash($error === null ? 'success' : 'danger', $error ?? 'Renamed. Its web address stays the same.');
        Response::redirect('/admin/tags');
        return 0;
    }

    private function delete(array $tag): int
    {
        $this->writer->delete((int) $tag['id']);
        Session::flash('success', 'Tag "' . $tag['name'] . '" deleted from every item.');
        Response::redirect('/admin/tags');
        return 0;
    }
}
