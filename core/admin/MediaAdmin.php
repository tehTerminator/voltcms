<?php
declare(strict_types=1);

namespace Cms;

/** Admin screens for the media library: browse, upload, edit alt text, delete. */
final class MediaAdmin
{
    private const PER_PAGE = 24;
    private const MAX_FILES_PER_UPLOAD = 20;

    private MediaRepository $repo;
    private MediaWriter $writer;

    public function __construct(private Database $db, private array $user)
    {
        $this->repo = new MediaRepository($db);
        $this->writer = new MediaWriter($db, CMS_ROOT . '/user/uploads', self::serverLimit());
    }

    /** The smaller of 5 MB, upload_max_filesize and post_max_size. */
    public static function serverLimit(): int
    {
        $limits = [5242880];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes((string) ini_get($key));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        return min($limits);
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** @return int 0 when handled, otherwise 404 or 405 */
    public function handle(string $path, string $method): int
    {
        $parts = array_values(array_filter(explode('/', substr($path, strlen('/admin/media'))), static fn (string $p): bool => $p !== ''));
        $id = isset($parts[0]) && ctype_digit($parts[0]) && strlen($parts[0]) < 10 ? (int) $parts[0] : null;
        if ($parts === []) {
            return $method === 'GET' ? $this->listPage() : 405;
        }
        if ($parts === ['upload']) {
            return $method === 'POST' ? $this->upload() : 405;
        }
        if ($parts === ['thumbnails']) {
            return $method === 'POST' ? $this->generateThumbnails() : 405;
        }
        $row = $id === null ? null : $this->repo->find($id);
        if ($row === null) {
            return 404;
        }
        if (count($parts) === 1) {
            return $method === 'GET' ? $this->detail($row) : ($method === 'POST' ? $this->save($row) : 405);
        }
        if (count($parts) === 2 && $parts[1] === 'delete') {
            return $method === 'POST' ? $this->delete($row) : 405;
        }
        if (count($parts) === 2 && $parts[1] === 'thumbnail') {
            return $method === 'POST' ? $this->regenerate($row) : 405;
        }
        return 404;
    }

    private function listPage(): int
    {
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $p = $_GET['page'] ?? '1';
        $page = is_string($p) && ctype_digit($p) && strlen($p) < 6 ? max(1, (int) $p) : 1;
        $result = $this->repo->list($page, self::PER_PAGE, $q);
        Response::html(View::render('media_list', [
            'title' => 'Media', 'nav' => true, 'rows' => $result['rows'], 'total' => $result['total'], 'q' => $q, 'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)), 'limit' => MediaWriter::formatBytes($this->writer->maxBytes()),
            'gd' => $this->writer->thumbnailsAvailable(), 'missingThumbs' => $this->repo->missingThumbnailCount(),
        ]));
        return 0;
    }

    private function upload(): int
    {
        $files = [];
        $f = $_FILES['files'] ?? null;
        if (is_array($f) && is_array($f['name'] ?? null)) {
            foreach (array_keys($f['name']) as $i) {
                $files[] = ['name' => (string) $f['name'][$i], 'tmp' => (string) $f['tmp_name'][$i], 'error' => (int) $f['error'][$i]];
            }
        }
        $ok = 0;
        $problems = [];
        foreach (array_slice($files, 0, self::MAX_FILES_PER_UPLOAD) as $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $problems[] = '"' . mb_substr(basename($file['name']), 0, 60) . '" ' . MediaWriter::uploadError($file['error']) . '.';
                continue;
            }
            $r = $this->writer->import($file['tmp'], $file['name'], (int) $this->user['id']);
            $r['ok'] ? $ok++ : $problems[] = (string) $r['error'];
        }
        if ($ok > 0) {
            Session::flash('success', $ok . ($ok === 1 ? ' file uploaded.' : ' files uploaded.'));
        }
        foreach (array_slice($problems, 0, 6) as $m) {
            Session::flash('danger', $m);
        }
        if ($ok === 0 && $problems === []) {
            Session::flash('warning', 'Choose at least one file to upload.');
        }
        Response::redirect('/admin/media');
        return 0;
    }

    /** Create thumbnails for older uploads, a time-limited batch per press. */
    private function generateThumbnails(): int
    {
        if (!$this->writer->thumbnailsAvailable()) {
            Session::flash('danger', 'This server has no PHP GD extension, so thumbnails cannot be created. Ask your host to enable GD.');
            Response::redirect('/admin/media');
            return 0;
        }
        $after = (int) (new Settings($this->db))->get('thumbs_after', '0');
        $r = $this->writer->generateMissing($after, microtime(true) + 15.0);
        $settings = new Settings($this->db);
        $settings->set('thumbs_after', (string) ($r['next'] ?? 0));
        $msg = $r['made'] . ' thumbnail(s) created' . ($r['skipped'] > 0 ? ', ' . $r['skipped'] . ' skipped (already small, unsupported or too large)' : '') . '.';
        Session::flash($r['next'] === null ? 'success' : 'warning', $r['next'] === null ? $msg . ' All images are done.' : $msg . ' More images remain: press the button again.');
        Response::redirect('/admin/media');
        return 0;
    }

    private function regenerate(array $row): int
    {
        if (!MediaRepository::isImage($row)) {
            return 404;
        }
        $status = $this->writer->makeThumbnail((int) $row['id'], (string) $row['path'], (string) $row['mime']);
        $text = ['ok' => 'Thumbnail created.', 'small' => 'The image is already small, so it needs no thumbnail.', 'skipped' => 'This server cannot create a thumbnail for this image (see the log for the reason).', 'failed' => 'The thumbnail could not be created.'];
        Session::flash($status === 'ok' || $status === 'small' ? 'success' : 'warning', $text[$status] ?? $status);
        Response::redirect('/admin/media/' . (int) $row['id']);
        return 0;
    }

    private function detail(array $row): int
    {
        Response::html(View::render('media_detail', [
            'title' => 'Media: ' . $row['original_name'], 'nav' => true, 'item' => $row, 'url' => MediaRepository::url($row),
            'isImage' => MediaRepository::isImage($row), 'usage' => $this->writer->usage((int) $row['id']), 'size' => MediaWriter::formatBytes((int) $row['size']),
        ]));
        return 0;
    }

    private function save(array $row): int
    {
        $this->writer->updateAlt((int) $row['id'], Request::post('alt_text'));
        Session::flash('success', 'Saved.');
        Response::redirect('/admin/media/' . (int) $row['id']);
        return 0;
    }

    private function delete(array $row): int
    {
        $error = $this->writer->delete((int) $row['id']);
        Session::flash($error === null ? 'success' : 'danger', $error ?? 'Deleted. The file was archived in user/backups/deleted/.');
        Response::redirect($error === null ? '/admin/media' : '/admin/media/' . (int) $row['id']);
        return 0;
    }
}
