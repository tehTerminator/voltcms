<?php
declare(strict_types=1);

namespace Cms;

/** Public site: home (latest articles), content pages by slug, private-page password gate, 404. */
final class PublicApp
{
    private const DEFAULT_PER_PAGE = 10;

    private Database $db;
    private ContentRepository $repo;
    private ContentStore $store;
    private CodeAssetRepository $codeAssets;
    private PublicRenderer $renderer;
    private TagRepository $tags;
    private int $perPage;

    public function __construct()
    {
        $this->db = App::db();
        $this->repo = new ContentRepository($this->db);
        $this->store = new ContentStore(CMS_ROOT . '/user');
        $this->codeAssets = new CodeAssetRepository($this->db);
        $this->tags = new TagRepository($this->db);
        $this->renderer = new PublicRenderer(App::settings(), $this->db);
        $per = (int) App::settings()->get('items_per_page', (string) self::DEFAULT_PER_PAGE);
        $this->perPage = $per >= 5 && $per <= 50 ? $per : self::DEFAULT_PER_PAGE;
    }

    public function handle(string $route, string $path, string $method): void
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if ($route === 'home') {
            if ($method !== 'GET') {
                $this->methodNotAllowed('GET, HEAD');
                return;
            }
            $this->home();
            return;
        }
        if ($route === 'search' || $route === 'tag') {
            if ($method !== 'GET') {
                $this->methodNotAllowed('GET, HEAD');
                return;
            }
            $route === 'search' ? $this->search() : $this->tag(substr($path, strlen('/tag/')));
            return;
        }
        $this->content(substr($path, 1), $method);
    }

    private function pageParam(): int
    {
        $p = $_GET['page'] ?? '1';
        return is_string($p) && ctype_digit($p) && strlen($p) < 6 ? max(1, (int) $p) : 1;
    }

    /** /tag/{slug}: published, public items with that tag. Unknown tags and tags with no public item are 404 (nothing leaks). */
    private function tag(string $slug): void
    {
        $tag = $this->tags->findBySlug($slug);
        if ($tag === null) {
            $this->notFound();
            return;
        }
        if ($tag['slug'] !== $slug) {
            Response::redirect('/tag/' . rawurlencode((string) $tag['slug']), 301);
            return;
        }
        $page = $this->pageParam();
        $result = $this->tags->publicItems((int) $tag['id'], $page, $this->perPage);
        if ($result['total'] === 0 || ($page > 1 && $result['rows'] === [])) {
            $this->notFound();
            return;
        }
        $site = $this->renderer->site();
        $this->send($this->renderer->render('tag', [
            'title' => 'Tag: ' . $tag['name'], 'tag' => $tag, 'items' => $result['rows'], 'total' => $result['total'], 'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $this->perPage)),
            'seo' => Seo::forList($site, 'Tag: ' . $tag['name'], '/tag/' . rawurlencode((string) $tag['slug']) . ($page > 1 ? '?page=' . $page : '')),
        ]));
    }

    /** /search?q=: results from the search index (published, public items only). The page itself is never indexed. */
    private function search(): void
    {
        $q = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
        $page = min(100, $this->pageParam());
        $searched = mb_strlen($q) >= 2;
        $result = $searched ? (new SearchIndex($this->db))->search($q, $page, $this->perPage) : ['rows' => [], 'total' => 0];
        $pages = max(1, (int) ceil($result['total'] / $this->perPage));
        if ($page > 1 && $page > $pages) {
            $this->notFound();
            return;
        }
        $this->send($this->renderer->render('search', [
            'title' => $q !== '' ? 'Search: ' . $q : 'Search', 'q' => $q, 'searched' => $searched, 'results' => $result['rows'],
            'total' => $result['total'], 'page' => $page, 'pages' => $pages, 'noindex' => true,
            'seo' => Seo::forList($this->renderer->site(), 'Search', '', '', 'noindex, follow'),
        ]));
    }

    private function home(): void
    {
        $page = $this->pageParam();
        $result = $this->repo->latestPublicArticles($page, $this->perPage);
        if ($page > 1 && $result['rows'] === []) {
            $this->notFound();
            return;
        }
        $this->send($this->renderer->render('home', [
            'title' => 'Latest articles',
            'articles' => $result['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $this->perPage)),
            'seo' => Seo::forList($this->renderer->site(), '', '/' . ($page > 1 ? '?page=' . $page : '')),
        ]));
    }

    private function content(string $address, string $method): void
    {
        $row = $this->repo->findByPath($address);
        if ($row === null) {
            // An address that used to exist (renamed slug, moved page, or a child page's old flat address): send visitors on.
            $target = $this->repo->redirectTarget($address);
            if ($target !== null && ($method === 'GET' || $method === 'HEAD')) {
                Response::redirect(Url::contentPath($target), 301);
                return;
            }
            $this->notFound();
            return;
        }
        if ($row['status'] !== 'published') {
            $this->notFound();
            return;
        }
        if ($row['path'] !== $address) { // matched case-insensitively: send visitors to the canonical address
            Response::redirect(Url::contentPath((string) $row['path']), 301);
            return;
        }
        if ($row['visibility'] === 'private' && !$this->unlocked($row, $method)) {
            return; // unlocked() has already sent the password form or a redirect
        }
        if ($method !== 'GET') {
            $this->methodNotAllowed('GET, HEAD');
            return;
        }
        $body = $this->store->read((int) $row['id']);
        if ($body === null) {
            Logger::error('Content body file missing', ['id' => (int) $row['id']]);
            $this->send($this->renderer->render('error', ['title' => 'Unavailable', 'code' => 503,
                'message' => 'This content is temporarily unavailable.']), 503);
            return;
        }
        $this->send($this->renderContent($row, $body, false), 200, $row['visibility'] === 'private');
    }

    /** Render a content row with its type template. Shared with the admin preview. */
    public function renderContent(array $row, string $body, bool $preview): string
    {
        $private = $row['visibility'] === 'private';
        $imageRow = $private || $row['og_image_media_id'] === null ? null : (new MediaRepository($this->db))->find((int) $row['og_image_media_id']);
        $seo = Seo::forContent($row, $this->renderer->site(), $imageRow);
        $vars = [
            'title' => $row['title'],
            'item' => $row,
            'body' => $body,
            'preview' => $preview,
            'noindex' => (int) $row['noindex'] === 1 || $private,
            'metaDescription' => $seo['description'],
            'seo' => $seo,
            'tags' => $private ? [] : $this->tags->forContent((int) $row['id']), // private pages show no tag links (their tag pages may not exist for visitors)
            'itemAssets' => $this->codeAssets->urlsForContent((int) $row['id']),
        ];
        if ($row['type'] === 'book') {
            $vars['crumbs'] = array_values(array_filter(
                $this->repo->ancestors((int) $row['id']),
                static fn (array $a): bool => $a['deleted_at'] === null && $a['status'] === 'published' && $a['visibility'] === 'public'
            ));
            $vars['children'] = $this->repo->publicChildren((int) $row['id']);
            $vars['neighbors'] = $this->repo->publicNeighbors($row);
        }
        // Most specific first: {type}-{slug} (ASCII slugs only), {type}, then the generic content template.
        $type = isset(ContentRepository::TYPES[$row['type']]) ? (string) $row['type'] : 'content';
        $candidates = [];
        if (preg_match('/^[a-z0-9_-]{1,60}$/', (string) $row['slug']) === 1) {
            $candidates[] = $type . '-' . $row['slug'];
        }
        $candidates[] = $type;
        $candidates[] = 'content';
        return $this->renderer->render($candidates, $vars);
    }

    /** Admin preview: same output as the public page, never cached, never indexed. */
    public function preview(array $row, string $body): void
    {
        $this->send($this->renderContent($row, $body, true), 200, true);
    }

    /**
     * Private pages: the administrator, or a visitor who entered the password in this session, may view.
     * Returns true when access is granted; otherwise it has already responded.
     */
    private function unlocked(array $row, string $method): bool
    {
        Session::start();
        $id = (int) $row['id'];
        if (is_int($_SESSION['uid'] ?? null) || isset($_SESSION['_unlocked'][$id])) {
            return true;
        }
        $error = '';
        $status = 200;
        if ($method === 'POST') {
            $limiter = new RateLimiter($this->db);
            $ip = Request::ip();
            $key = 'page:' . $id;
            if (!Csrf::verify()) {
                $error = 'The form expired. Please try again.';
            } elseif ($limiter->blocked($ip, $key)) {
                $error = 'Too many attempts. Please wait 15 minutes and try again.';
                $status = 429;
            } elseif ($row['view_password_hash'] !== null && password_verify(Request::post('password'), (string) $row['view_password_hash'])) {
                $_SESSION['_unlocked'][$id] = time();
                $limiter->clear($ip, $key);
                Response::redirect(Url::contentPath((string) $row['path']));
                return false;
            } else {
                $limiter->fail($ip, $key);
                Logger::security('page_password_failed', ['id' => $id, 'ip' => $ip]);
                $error = 'That password is not correct.';
            }
        }
        $this->send($this->renderer->render('private', ['title' => 'Private page', 'noindex' => true, 'error' => $error, 'item' => $row]), $status, true);
        return false;
    }

    /** The page visitors see while maintenance mode is on: 503, not cached, not indexed. */
    public function maintenance(string $message): void
    {
        $message = trim($message) !== '' ? trim($message) : 'The site is down for maintenance. Please check back soon.';
        header('Retry-After: 3600');
        $this->send($this->renderer->render('error', ['title' => 'Maintenance', 'code' => 503, 'message' => $message, 'noindex' => true]), 503, true);
    }

    public function notFound(): void
    {
        $this->send($this->renderer->render('404', ['title' => 'Page not found', 'noindex' => true]), 404);
    }

    private function methodNotAllowed(string $allow): void
    {
        header('Allow: ' . $allow);
        $this->send($this->renderer->render('error', ['title' => 'Method not allowed', 'code' => 405,
            'message' => 'Method not allowed.', 'noindex' => true]), 405);
    }

    private function send(string $html, int $status = 200, bool $private = false): void
    {
        Response::publicHeaders($this->renderer->nonce());
        if ($private) {
            Response::noCache();
            header('X-Robots-Tag: noindex, nofollow');
        }
        Response::html($html, $status);
    }
}
