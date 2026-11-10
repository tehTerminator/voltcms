<?php
declare(strict_types=1);

/**
 * Dependency-free test runner:  php tests/run.php
 * Optional database tests: CMS_TEST_DB="host|port|name|user|pass" php tests/run.php
 * (use an empty throwaway database; tables are created with prefix "t_" and dropped afterwards).
 */
define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/core/engine/bootstrap.php';

use Cms\Config;
use Cms\Crypto;
use Cms\Database;
use Cms\Migrator;
use Cms\RateLimiter;
use Cms\Request;
use Cms\Router;
use Cms\Settings;
use Cms\ContentRepository;
use Cms\ContentStore;
use Cms\ContentWriter;
use Cms\Sanitizer;
use Cms\Slug;
use Cms\PublicRenderer;
use Cms\Theme;
use Cms\ThemeAssets;
use Cms\ThemeValidator;
use Cms\CodeAssetConverter;
use Cms\CodeAssetRepository;
use Cms\CodeAssetStore;
use Cms\CodeAssetWriter;
use Cms\MediaRepository;
use Cms\MediaWriter;
use Cms\SearchIndex;
use Cms\SettingsAdmin;
use Cms\SiteInfo;
use Cms\TagRepository;
use Cms\TagWriter;
use Cms\Thumbnailer;
use Cms\AutocompleteField;
use Cms\LookupAdmin;
use Cms\LookupService;
use Cms\Seo;
use Cms\Syndication;
use Cms\Format;
use Cms\Totp;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
}

Config::inject(['app_key' => base64_encode(str_repeat('k', 32))]);

echo "Totp\n";
$rfcSecret = Totp::base32Encode('12345678901234567890');
check('base32 of RFC secret', $rfcSecret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
check('base32 round trip', Totp::base32Decode(Totp::base32Encode("\x00\xffabc")) === "\x00\xffabc");
check('RFC 6238 vector T=59', Totp::code($rfcSecret, intdiv(59, 30)) === '287082');
check('RFC 6238 vector T=1111111109', Totp::code($rfcSecret, intdiv(1111111109, 30)) === '081804');
$now = 1111111109;
$step = intdiv($now, 30);
check('verify accepts current step', Totp::verify($rfcSecret, '081804', null, $now) === $step);
check('verify rejects wrong code', Totp::verify($rfcSecret, '000000', null, $now) === null);
check('verify blocks replay of used step', Totp::verify($rfcSecret, '081804', $step, $now) === null);
check('verify accepts previous step (clock drift)', Totp::verify($rfcSecret, Totp::code($rfcSecret, $step - 1), null, $now) === $step - 1);
check('verify rejects 2 steps old', Totp::verify($rfcSecret, Totp::code($rfcSecret, $step - 2), null, $now) === null);
check('generated secret is 32 chars of base32', preg_match('/^[A-Z2-7]{32}$/', Totp::generateSecret()) === 1);

echo "Crypto\n";
$enc = Crypto::encrypt('secret-value');
check('encrypt/decrypt round trip', Crypto::decrypt($enc) === 'secret-value');
check('ciphertext differs each time', Crypto::encrypt('secret-value') !== $enc);
$raw = base64_decode($enc, true);
$tampered = base64_encode(substr($raw, 0, -1) . chr(ord($raw[strlen($raw) - 1]) ^ 1));
check('tampered ciphertext rejected', Crypto::decrypt($tampered) === null);
check('garbage rejected', Crypto::decrypt('not base64 !!') === null);
check('hmac stable and keyed', Crypto::hmac('a') === Crypto::hmac('a') && Crypto::hmac('a') !== Crypto::hmac('b'));

echo "Request::path\n";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$paths = [
    '/a//b/?x=1' => '/a/b', '/' => '/', '' => '/', '/admin/login' => '/admin/login',
    '/../etc/passwd' => null, '/a/%2e%2e/b' => null, '/a/%00' => null, '//evil.com/x' => '/evil.com/x',
];
foreach ($paths as $uri => $expected) {
    $_SERVER['REQUEST_URI'] = $uri;
    check('path ' . var_export($uri, true), Request::path() === $expected);
}
$_SERVER['SCRIPT_NAME'] = '/blog/index.php';
$_SERVER['REQUEST_URI'] = '/blog/admin/login';
check('subfolder base stripped', Request::path() === '/admin/login');
$_SERVER['REQUEST_URI'] = '/other/admin';
check('path outside subfolder is null', Request::path() === null);
$_SERVER['SCRIPT_NAME'] = '/index.php';

echo "Router\n";
check('admin', Router::resolve('/admin') === 'admin' && Router::resolve('/admin/x') === 'admin');
check('administrator is not admin', Router::resolve('/administrator') === 'content');
check('any address of 1 to 12 segments is content; deeper or empty is not', Router::resolve('/some-slug') === 'content' && Router::resolve('/a/b/c') === 'content' && Router::resolve('/' . implode('/', range(1, 12))) === 'content' && Router::resolve('/' . implode('/', range(1, 13))) === 'notfound');
check('home / null', Router::resolve('/') === 'home' && Router::resolve(null) === 'notfound');
check('base asset route', Router::resolve('/assets/base/css/theme.css') === 'asset' && Router::resolve('/assets') === 'content');
check('fixed public routes', Router::resolve('/search') === 'search' && Router::resolve('/feed.xml') === 'feed' && Router::resolve('/sitemap.xml') === 'sitemap' && Router::resolve('/robots.txt') === 'robots' && Router::resolve('/tag/law') === 'tag');
check('near-misses of fixed routes are content addresses', Router::resolve('/tag') === 'content' && Router::resolve('/tag/a/b') === 'content' && Router::resolve('/search/x') === 'content' && Router::resolve('/feed.xml/x') === 'content');

echo "Migrator::splitStatements\n";
$parts = Migrator::splitStatements("-- comment; with semicolon\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (\n id INT\n);\n");
check('two statements, comments dropped', count($parts) === 2 && str_starts_with($parts[1], 'CREATE TABLE b'));


echo "Slug\n";
check('slug from title', Slug::fromTitle('Hello, World!') === 'hello-world');
check('slug collapses punctuation and spaces', Slug::fromTitle("  Multiple   spaces & symbols!! ") === 'multiple-spaces-symbols');
check('slug keeps Hindi letters and vowel signs', Slug::fromTitle('भारतीय संविधान') === 'भारतीय-संविधान' && Slug::isValid('भारतीय-संविधान'));
check('slug of only symbols falls back', Slug::fromTitle('!!!') === 'untitled');
check('slug validity rules', Slug::isValid('a-b') && !Slug::isValid('a--b') && !Slug::isValid('-a') && !Slug::isValid('a b') && !Slug::isValid('') && !Slug::isValid(str_repeat('a', 121)) && !Slug::isValid('a/b') && !Slug::isValid('a.b'));
check('reserved slugs (case-insensitive)', Slug::isReserved('Admin') && Slug::isReserved('feed.xml') && !Slug::isReserved('about'));

echo "Sanitizer\n";
$san = new Sanitizer();
$no = static function (string $name, string $input, array $forbidden) use ($san): void {
    $out = $san->clean($input);
    $bad = array_filter($forbidden, static fn (string $f): bool => stripos($out, $f) !== false);
    check($name, $bad === [], $out);
};
$no('script removed', '<p>Hi</p><script>alert(1)</script><SCRIPT SRC=x></SCRIPT>', ['<script', 'alert']);
check('text around script kept', str_contains($san->clean('<p>Hi</p><script>x</script>'), '<p>Hi</p>'));
$no('img onerror dropped', '<img src=x onerror=alert(1)>', ['onerror', 'alert']);
$no('javascript: href dropped', '<a href="javascript:alert(1)">x</a>', ['javascript']);
$no('entity-obfuscated javascript: dropped', '<a href="java&#x09;script:alert(1)">x</a><a href=" jAvAsCrIpT:alert(1)">y</a>', ['script:']);
$no('data: href dropped', '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', ['data:']);
$no('svg removed', '<svg onload=alert(1)><circle/></svg>', ['svg', 'onload']);
$no('svg data image removed', '<img src="data:image/svg+xml;base64,PHN2Zz4=">', ['data:']);
$no('iframe and object removed', '<iframe src="//evil"></iframe><object data="x"></object><embed src="x">', ['iframe', 'object', 'embed']);
$no('form controls removed', '<form action="/x"><input name="a"><textarea>t</textarea></form>', ['<form', '<input', 'textarea']);
$no('event handlers dropped', '<p onclick="x()" onmouseover=alert(1)>t</p>', ['onclick', 'onmouseover']);
$no('broken-tag trick', '<scr<script>ipt>alert(1)</scr</script>ipt>', ['<script']);
$no('style url() and position dropped', '<p style="color:red;background:url(javascript:alert(1));position:fixed">t</p>', ['url(', 'position', 'javascript']);
check('safe style kept', str_contains($san->clean('<p style="color: red; text-align:center">t</p>'), 'color: red; text-align: center'));
$no('css expression dropped', '<p style="width:expression(alert(1))">t</p>', ['expression', 'style=']);
$no('comments removed', '<p>a<!-- secret --></p>', ['secret', '<!--']);
check('button forced to type=button', str_contains($san->clean('<button type="submit" onclick="x()">b</button>'), 'type="button"'));
check('target=_blank gets rel', str_contains($san->clean('<a href="https://e.com" target="_blank">x</a>'), 'rel="noopener noreferrer"'));
check('bootstrap markup preserved', $san->clean('<div class="row"><div class="col-md-6" data-bs-toggle="collapse" aria-label="x">ok</div></div>') === '<div class="row"><div class="col-md-6" data-bs-toggle="collapse" aria-label="x">ok</div></div>');
check('relative, mailto and fragment links kept', $san->clean('<a href="/a">1</a><a href="#top">2</a><a href="mailto:a@b.c">3</a>') === '<a href="/a">1</a><a href="#top">2</a><a href="mailto:a@b.c">3</a>');
check('png data image kept', str_contains($san->clean('<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">'), 'data:image/png'));
check('tables preserved', str_contains($san->clean('<table class="table"><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><td colspan="2">1</td></tr></tbody></table>'), '<td colspan="2">1</td>'));
check('unknown element unwrapped', $san->clean('<font color="red">hi</font>') === 'hi');
check('Hindi text is not turned into entities', $san->clean('<p>भारतीय संविधान</p>') === '<p>भारतीय संविधान</p>');
check('Unicode, quotes and ampersands survive', $san->clean('<p>Tom &amp; "Jerry" é ñ</p>') === '<p>Tom &amp; "Jerry" é ñ</p>');
$sample = '<h2>T</h2><p>x <b>y</b> <a href="javascript:1" onclick="2">z</a></p><ul><li>1</li></ul>';
check('sanitising is idempotent', $san->clean($san->clean($sample)) === $san->clean($sample));
check('empty input', $san->clean('') === '' && $san->clean("  \n") === '');

echo "ContentStore\n";
$tmp = sys_get_temp_dir() . '/cms_store_' . bin2hex(random_bytes(4));
mkdir($tmp . '/content/posts', 0755, true);
$store = new ContentStore($tmp);
check('missing body reads as null', $store->read(1) === null && !$store->exists(1));
check('write then read', $store->write(1, '<p>one</p>') && $store->read(1) === '<p>one</p>' && $store->exists(1));
$store->write(1, '<p>two</p>');
check('overwrite is atomic and complete', $store->read(1) === '<p>two</p>' && glob($tmp . '/content/posts/.tmp_*') === []);
$store->write(7, 'x');
file_put_contents($tmp . '/content/posts/notes.txt', 'ignore me');
check('scan lists numeric html files only', $store->scanPostIds() === [1, 7]);
check('revision write/read/delete', $store->writeRevision(1, 5, 'old') && $store->readRevision(1, 5) === 'old' && ($store->deleteRevision(1, 5) ?? true) && $store->readRevision(1, 5) === null);
$store->writeRevision(1, 6, 'rev');
$dir = $store->archive(1, ['id' => 1, 'title' => 'T']);
check('archive moves body and revisions, writes meta', $dir !== null && is_file($dir . '/body.html') && is_file($dir . '/revisions/6.html') && is_file($dir . '/meta.json') && !$store->exists(1));
check('archive of item without files still succeeds', $store->archive(99, ['id' => 99]) !== null);
exec('rm -rf ' . escapeshellarg($tmp));

echo "Theme resolution\n";
$rm = static function (string $dir): void { exec('rm -rf ' . escapeshellarg($dir)); };
$mkfile = static function (string $path, string $data = ''): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0755, true); }
    file_put_contents($path, $data);
};
$tcore = sys_get_temp_dir() . '/cms_tcore_' . bin2hex(random_bytes(4));
$tuser = sys_get_temp_dir() . '/cms_tuser_' . bin2hex(random_bytes(4));
mkdir($tcore . '/themes', 0755, true);
exec('cp -r ' . escapeshellarg(CMS_ROOT . '/core/themes/base') . ' ' . escapeshellarg($tcore . '/themes/base'));
mkdir($tuser . '/themes', 0755, true);

foreach (['css/theme.css' => true, 'a/b.c' => true, 'x' => true, '' => false, '../x' => false, 'a/../b' => false, '/abs' => false, 'a//b' => false, '.hidden' => false, 'a\\b' => false, 'a b' => false, 'a/.b' => false] as $p => $expected) {
    check('isSafeRelative ' . var_export($p, true), Theme::isSafeRelative($p) === $expected);
}
$base = new Theme('base', $tuser, $tcore);
check('base locates layout, template and partial', $base->locate('layout.php')['source'] === 'base' && $base->locate('templates/article.php') !== null && $base->locate('partials/footer.php') !== null);
check('missing and traversal paths resolve to null', $base->locate('templates/nope.php') === null && $base->locate('../../etc/passwd') === null && $base->locate('/etc/passwd') === null);

$mkfile($tuser . '/themes/kid/theme.json', json_encode(['name' => 'Kid', 'version' => '1.0.0', 'parent' => 'base', 'assets' => ['css' => ['css/kid.css'], 'js' => []]]));
$mkfile($tuser . '/themes/kid/partials/footer.php', '<footer>KID FOOTER</footer>');
$mkfile($tuser . '/themes/kid/assets/css/kid.css', 'body{}');
$mkfile($tuser . '/themes/kid/assets/css/theme.css', '/* override of base theme.css */');
$mkfile($tuser . '/themes/kid/assets/css/templates/article.css', '/* article only */');
$kid = new Theme('kid', $tuser, $tcore);
check('child file wins over base', $kid->locate('partials/footer.php')['source'] === 'child');
check('files the child lacks come from base', $kid->locate('partials/header.php')['source'] === 'base' && $kid->locate('layout.php')['source'] === 'base');
check('isChild/name/baseTheme', $kid->isChild() && $kid->name() === 'kid' && !$kid->baseTheme()->isChild() && !$base->isChild());
@symlink('/etc/hostname', $tuser . '/themes/kid/partials/evil.php');
check('symlink escaping the theme folder is refused', is_link($tuser . '/themes/kid/partials/evil.php') && $kid->locate('partials/evil.php') === null);

echo "Theme URLs and assets\n";
$_SERVER['SCRIPT_NAME'] = '/index.php';
check('base asset URL goes through /assets/base with version', $base->url('css/theme.css') === '/assets/base/css/theme.css?v=' . rawurlencode(CMS_VERSION));
check('child asset URL is a direct static path with mtime', preg_match('#^/user/themes/kid/assets/css/kid\.css\?v=\d+$#', $kid->url('css/kid.css')) === 1);
check('child override of a base asset path wins', str_starts_with($kid->url('css/theme.css'), '/user/themes/kid/assets/css/theme.css'));
check('child falls back to a base asset it does not have', str_starts_with($kid->url('js/theme.js'), '/assets/base/js/theme.js'));
check('unknown asset gives empty string, traversal too', $kid->url('css/none.css') === '' && $kid->url('../theme.json') === '');
check('no argument gives the active theme assets root', $base->url() === '/assets/base' && $kid->url() === '/user/themes/kid/assets');
$_SERVER['SCRIPT_NAME'] = '/blog/index.php';
check('URLs respect a subfolder install', str_starts_with($base->url('css/theme.css'), '/blog/assets/base/css/theme.css') && str_starts_with($kid->url('css/kid.css'), '/blog/user/themes/kid/'));
$_SERVER['SCRIPT_NAME'] = '/index.php';
$a = $base->assets('page');
check('base assets: global css and js only', count($a['css']) === 1 && count($a['js']) === 1 && str_contains($a['css'][0], 'css/theme.css'));
$a = $base->assets('book');
check('template-specific css loaded for that template only', count($a['css']) === 2 && str_contains($a['css'][1], 'templates/book.css'));
$a = $kid->assets('article');
check('child: base global, child global, then template-specific', count($a['css']) === 3 && str_contains($a['css'][0], 'kid/assets/css/theme.css') && str_contains($a['css'][1], 'kid.css') && str_contains($a['css'][2], 'templates/article.css'));
check('child assets for another template omit the article css', count($kid->assets('page')['css']) === 2);
check('invalid template names add no assets', count($kid->assets('../x')['css']) === 2);

check('forSite: unknown or malformed theme falls back to base', Theme::forSite('ghost', $tuser, $tcore)->name() === 'base' && Theme::forSite('../kid', $tuser, $tcore)->name() === 'base' && Theme::forSite(null, $tuser, $tcore)->name() === 'base' && Theme::forSite('kid', $tuser, $tcore)->name() === 'kid');
$mkfile($tuser . '/themes/junk/theme.json', '{not json');
check('readMeta survives garbage', Theme::readMeta($tuser . '/themes/junk/theme.json', 'junk')['title'] === 'junk' && Theme::readMeta('/nonexistent', 'x')['assets'] === ['css' => [], 'js' => []]);
check('readMeta drops unsafe asset paths', Theme::readMeta((function () use ($mkfile, $tuser) { $f = $tuser . '/themes/junk/m.json'; $mkfile($f, json_encode(['assets' => ['css' => ['../../x.css', 'ok.css', 5]]])); return $f; })(), 'm')['assets']['css'] === ['ok.css']);
check('safeFallback needs no files and escapes', str_contains(Theme::safeFallback(['title' => '<b>', 'site' => ['name' => 'S'], 'body' => '<p>x</p>']), '&lt;b&gt;') && str_contains(Theme::safeFallback([]), '<!doctype html>'));

echo "ThemeValidator\n";
$val = new ThemeValidator($tuser, $tcore);
check('a theme containing a symlink is rejected', in_array(true, array_map(static fn ($e) => str_contains($e, 'Symbolic links'), $val->validate('kid')['errors']), true));
@unlink($tuser . '/themes/kid/partials/evil.php');
$good = $val->validate('kid');
check('valid child theme passes', $good['errors'] === [] && $good['meta']['title'] === 'Kid', $good['errors']);
$hasErr = static function (string $name, string $needle) use ($val): bool {
    foreach ($val->validate($name)['errors'] as $e) { if (stripos($e, $needle) !== false) { return true; } }
    return false;
};
check('reserved and malformed names rejected', $hasErr('base', 'folder name') && $hasErr('Bad_Name', 'folder name'));
check('missing folder', $hasErr('nothere', 'does not exist'));
check('broken JSON', $hasErr('junk', 'not valid JSON'));
$mk = static function (string $name, array $manifest, array $files = []) use ($mkfile, $tuser): void {
    $mkfile($tuser . '/themes/' . $name . '/theme.json', json_encode($manifest));
    foreach ($files as $p => $c) { $mkfile($tuser . '/themes/' . $name . '/' . $p, $c); }
};
$mk('nover', ['name' => 'N']);
check('version required', $hasErr('nover', 'version'));
$mk('noname', ['version' => '1.0.0']);
check('name required', $hasErr('noname', '"name"'));
$mk('badparent', ['name' => 'B', 'version' => '1.0.0', 'parent' => 'other']);
check('parent must be base', $hasErr('badparent', 'parent'));
$mk('future', ['name' => 'F', 'version' => '1.0.0', 'requires' => ['cms' => '>=9.0.0']]);
check('incompatible CMS version', $hasErr('future', 'needs CMS version'));
$mk('badreq', ['name' => 'F', 'version' => '1.0.0', 'requires' => ['cms' => 'latest']]);
check('malformed requirement', $hasErr('badreq', 'requires.cms'));
$mk('noasset', ['name' => 'A', 'version' => '1.0.0', 'assets' => ['css' => ['css/missing.css']]]);
check('listed asset must exist', $hasErr('noasset', 'was not found'));
$mk('basecss', ['name' => 'A', 'version' => '1.0.0', 'assets' => ['css' => ['css/theme.css']]]);
check('asset may come from the base theme', $val->validate('basecss')['errors'] === []);
$mk('evilasset', ['name' => 'A', 'version' => '1.0.0', 'assets' => ['css' => ['../../x.css'], 'js' => 'x.js']]);
check('unsafe asset paths and wrong types rejected', $hasErr('evilasset', 'invalid path') && $hasErr('evilasset', 'assets.js'));
$mk('syntax', ['name' => 'S', 'version' => '1.0.0'], ['templates/page.php' => "<?php if ( { ?>"]);
check('PHP syntax errors are caught with file and line', $hasErr('syntax', 'syntax error in templates/page.php on line'));
$mk('okphp', ['name' => 'S', 'version' => '1.0.0'], ['templates/page.php' => "<h1><?= e(\$item['title']) ?></h1>"]);
check('valid PHP template passes', $val->validate('okphp')['errors'] === []);
$mk('badext', ['name' => 'S', 'version' => '1.0.0'], ['assets/evil.phar' => 'x', 'assets/run.exe' => 'x', 'assets/.htaccess' => 'x']);
check('disallowed file types rejected', count(array_filter($val->validate('badext')['errors'], static fn ($e) => str_contains($e, 'not allowed'))) === 3);
$mk('stray', ['name' => 'S', 'version' => '1.0.0'], ['functions.php' => '<?php // never loaded']);
check('PHP file outside layout/templates/partials only warns', $val->validate('stray')['errors'] === [] && str_contains(implode(' ', $val->validate('stray')['warnings']), 'never loaded'));
$mk('linked', ['name' => 'S', 'version' => '1.0.0']);
@symlink('/etc', $tuser . '/themes/linked/etc');
check('symlinks rejected', $hasErr('linked', 'Symbolic links'));
check('compatibilityProblem()', ThemeValidator::compatibilityProblem('>=0.3.0', '0.3.0-phase3') === null && ThemeValidator::compatibilityProblem('>=0.4.0', '0.3.0-phase3') !== null && ThemeValidator::compatibilityProblem('1.0', '1.0.2') === null && ThemeValidator::compatibilityProblem('<1.0.0', '1.0.0') !== null && ThemeValidator::compatibilityProblem('nonsense') !== null);
check('shipped base theme manifest is valid', Theme::readMeta(CMS_ROOT . '/core/themes/base/theme.json', 'base')['title'] === 'Base' && ThemeValidator::compatibilityProblem(Theme::readMeta(CMS_ROOT . '/core/themes/base/theme.json', 'base')['requires']['cms'] ?? '') === null);
$allPhpOk = true;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMS_ROOT . '/core/themes/base', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() === 'php') { try { token_get_all(file_get_contents($f->getPathname()), TOKEN_PARSE); } catch (ParseError) { $allPhpOk = false; } }
}
check('every shipped base theme PHP file parses', $allPhpOk);

echo "ThemeAssets (base theme files)\n";
$serve = static function (string $rel, string $method = 'GET') use ($tcore): string {
    ob_start();
    @ThemeAssets::serve($rel, $method, $tcore); // headers cannot be sent in this CLI test; only the body is checked
    return (string) ob_get_clean();
};
check('serves a base asset byte for byte', $serve('css/theme.css') === file_get_contents($tcore . '/themes/base/assets/css/theme.css'));
check('HEAD sends no body', $serve('css/theme.css', 'HEAD') === '');
check('unknown file, traversal, wrong extension and dotfile are 404', $serve('css/none.css') === 'Not found' && $serve('../theme.json') === 'Not found' && $serve('css/../../layout.php') === 'Not found' && $serve('theme.json') === 'Not found' && $serve('.x') === 'Not found');
$rm($tcore); $rm($tuser);

echo "Tags, search text, site URL (pure functions)\n";
[$n, $e] = TagWriter::parse("law, Constitution ,LAW,\n notes  of   class ，अनुच्छेद 21");
check('tag parsing: trims, collapses spaces, de-duplicates case-insensitively, accepts Hindi comma and newline', $n === ['law', 'Constitution', 'notes of class', 'अनुच्छेद 21'] && $e === []);
check('tag parsing: empty input', TagWriter::parse('  , ,') === [[], []]);
[$n, $e] = TagWriter::parse(str_repeat('x', 61));
check('tag parsing: over-long tag rejected', $n === [] && $e !== []);
[$n, $e] = TagWriter::parse(implode(',', range(1, 16)));
check('tag parsing: more than 15 tags rejected', count($e) === 1);
check('search terms: lower-case words, unique, Hindi kept, punctuation dropped', SearchIndex::terms('Article 21, ARTICLE 21! अनुच्छेद-21') === ['article', '21', 'अनुच्छेद']);
check('search terms: capped at 8 and empty for symbols only', count(SearchIndex::terms('a b c d e f g h i j k')) === 8 && SearchIndex::terms('!!! ???') === []);
check('search plain text strips tags and entities', SearchIndex::plainText('<p>Tom &amp; <b>Jerry</b></p><p>x</p>') === 'Tom & Jerry x');
check('site URL cleaning', SettingsAdmin::cleanSiteUrl('https://Example.com/') === 'https://example.com' && SettingsAdmin::cleanSiteUrl('http://example.com/blog/') === 'http://example.com/blog' && SettingsAdmin::cleanSiteUrl('https://example.com:8080') === 'https://example.com:8080' && SettingsAdmin::cleanSiteUrl('') === '');
check('site URL rejects bad values', SettingsAdmin::cleanSiteUrl('example.com') === null && SettingsAdmin::cleanSiteUrl('ftp://example.com') === null && SettingsAdmin::cleanSiteUrl('https://example.com?x=1') === null && SettingsAdmin::cleanSiteUrl('https://user@example.com') === null && SettingsAdmin::cleanSiteUrl('javascript:alert(1)') === null && SettingsAdmin::cleanSiteUrl('https://exa mple.com') === null);

echo "SEO builder (pure functions)\n";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$siteS = ['name' => 'My Site', 'lang' => 'en', 'url' => 'https://example.com', 'description' => 'Site wide description', 'logo' => '', 'logo_alt' => '', 'social_image' => '/user/uploads/2026/10/site.png', 'social_image_path' => '2026/10/site.png'];
$artRow = ['type' => 'article', 'title' => 'Hello World', 'slug' => 'hello-world', 'path' => 'hello-world', 'seo_title' => '', 'meta_description' => '', 'excerpt' => 'An excerpt', 'noindex' => 0, 'visibility' => 'public',
    'published_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 11:30:00', 'og_image_media_id' => null];
check('clip: strips tags and entities, collapses spaces, leaves short text alone', Seo::clip("<p>Tom &amp;  <b>Jerry</b>\n\n x</p>") === 'Tom & Jerry x');
$long = str_repeat('word ', 100);
$clipped = Seo::clip($long);
check('clip: long text is cut at a word boundary within 300 characters with an ellipsis', mb_strlen($clipped) <= 300 && str_ends_with($clipped, '…') && !str_contains($clipped, 'wor…'));
check('clip: Hindi text is cut by characters, not bytes', mb_strlen(Seo::clip(str_repeat('अनुच्छेद ', 80))) <= 300 && mb_check_encoding(Seo::clip(str_repeat('अनुच्छेद ', 80)), 'UTF-8'));
check('absolute URLs need a site URL (subfolder included)', Seo::absolute($siteS, '/a') === 'https://example.com/a' && Seo::absolute(['url' => ''] + $siteS, '/a') === '' && Seo::absolute(['url' => 'https://x.com/blog'] + $siteS, '/tag/law') === 'https://x.com/blog/tag/law');
$seo = Seo::forContent($artRow, $siteS, null);
check('article: canonical, Open Graph article type, URL, title, description from the excerpt', $seo['canonical'] === 'https://example.com/hello-world' && $seo['og']['og:type'] === 'article' && $seo['og']['og:url'] === $seo['canonical'] && $seo['og']['og:title'] === 'Hello World' && $seo['og']['og:description'] === 'An excerpt' && $seo['og']['og:site_name'] === 'My Site');
check('article: published and modified times in ISO 8601', $seo['og']['article:published_time'] === '2026-10-01T10:00:00+00:00' && $seo['og']['article:modified_time'] === '2026-10-02T11:30:00+00:00');
check('image falls back to the site default; twitter card is large when an image exists', $seo['og']['og:image'] === 'https://example.com/user/uploads/2026/10/site.png' && $seo['twitter']['twitter:card'] === 'summary_large_image' && $seo['twitter']['twitter:image'] === $seo['og']['og:image'] && !isset($seo['og']['og:image:alt']));
$seo = Seo::forContent($artRow, $siteS, ['path' => '2026/10/own.jpg', 'alt_text' => 'Own picture']);
check("the page's own social image wins and carries its alt text", $seo['og']['og:image'] === 'https://example.com/user/uploads/2026/10/own.jpg' && $seo['og']['og:image:alt'] === 'Own picture');
$seo = Seo::forContent(['social_image_path' => ''] + $artRow, ['social_image_path' => '', 'social_image' => ''] + $siteS, null);
check('no image anywhere: summary card, no image tags', $seo['twitter']['twitter:card'] === 'summary' && !isset($seo['og']['og:image']) && !isset($seo['twitter']['twitter:image']));
$seo = Seo::forContent(['type' => 'page', 'published_at' => null] + $artRow, $siteS, null);
check('pages are og:type website and have no article times', $seo['og']['og:type'] === 'website' && !isset($seo['og']['article:published_time']));
$seo = Seo::forContent(['seo_title' => 'Custom SEO Title'] + $artRow, $siteS, null);
check('an SEO title overrides the title (also in og:title)', $seo['title'] === 'Custom SEO Title' && $seo['og']['og:title'] === 'Custom SEO Title');
check('description fallback: meta description, then excerpt, then the site description', Seo::forContent(['meta_description' => 'Meta text'] + $artRow, $siteS, null)['description'] === 'Meta text' && Seo::forContent($artRow, $siteS, null)['description'] === 'An excerpt' && Seo::forContent(['excerpt' => ''] + $artRow, $siteS, null)['description'] === 'Site wide description');
$seo = Seo::forContent(['noindex' => 1] + $artRow, $siteS, null);
check('noindex pages: robots directive, no canonical, no social tags', $seo['robots'] === 'noindex, nofollow' && $seo['canonical'] === '' && $seo['og'] === [] && $seo['twitter'] === []);
$seo = Seo::forContent(['visibility' => 'private', 'meta_description' => 'secret words'] + $artRow, $siteS, ['path' => 'x.jpg', 'alt_text' => '']);
check('private pages offer nothing to link previews (no description, canonical, og, twitter)', $seo['robots'] === 'noindex, nofollow' && $seo['description'] === '' && $seo['canonical'] === '' && $seo['og'] === [] && $seo['twitter'] === []);
$seo = Seo::forContent($artRow, ['url' => ''] + $siteS, ['path' => 'x.jpg', 'alt_text' => '']);
check('without a site URL: no canonical, no og:url, no image, no feed link', $seo['canonical'] === '' && !isset($seo['og']['og:url']) && !isset($seo['og']['og:image']) && $seo['feed'] === '');
check('a child page uses its full address; Hindi segments are percent-encoded', Seo::forContent(['path' => 'book/part-one'] + $artRow, $siteS, null)['canonical'] === 'https://example.com/book/part-one' && Seo::forContent(['path' => 'किताब/अनुच्छेद'] + $artRow, $siteS, null)['canonical'] === 'https://example.com/%E0%A4%95%E0%A4%BF%E0%A4%A4%E0%A4%BE%E0%A4%AC/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6');
check('feed link is the absolute feed address', Seo::forContent($artRow, $siteS, null)['feed'] === 'https://example.com/feed.xml');
$seo = Seo::forList($siteS, '', '/?page=2');
check('home list: canonical with page, site description, default image, website type', $seo['canonical'] === 'https://example.com/?page=2' && $seo['description'] === 'Site wide description' && $seo['og']['og:type'] === 'website' && $seo['og']['og:title'] === 'My Site' && $seo['og']['og:image'] === 'https://example.com/user/uploads/2026/10/site.png');
$seo = Seo::forList($siteS, 'Search', '', '', 'noindex, follow');
check('utility pages marked noindex have no canonical or social tags', $seo['robots'] === 'noindex, follow' && $seo['canonical'] === '' && $seo['og'] === []);
check('tag page title in og:title', Seo::forList($siteS, 'Tag: Law', '/tag/law')['og']['og:title'] === 'Tag: Law | My Site');

echo "Display formatting (time zone and date format)\n";
Format::configure('UTC', 'j M Y');
check('default: UTC, "21 Oct 2026" style', Format::date('2026-10-21 12:00:00') === '21 Oct 2026' && Format::isoDate('2026-10-21 12:00:00') === '2026-10-21');
Format::configure('Asia/Kolkata', 'j M Y');
check('IST shifts a late-evening UTC timestamp to the next day (20:00 UTC = 01:30 IST)', Format::date('2026-10-01 20:00:00') === '2 Oct 2026' && Format::isoDate('2026-10-01 20:00:00') === '2026-10-02' && Format::date('2026-10-01 20:00:00', 'H:i') === '01:30');
check('an early-morning UTC timestamp stays on the same day in IST', Format::date('2026-10-01 05:00:00') === '1 Oct 2026');
Format::configure('America/New_York', 'j M Y');
check('daylight saving is handled (summer UTC-4, winter UTC-5)', Format::date('2026-07-01 03:00:00', 'Y-m-d H:i') === '2026-06-30 23:00' && Format::date('2026-12-01 03:00:00', 'Y-m-d H:i') === '2026-11-30 22:00');
foreach (['j M Y' => '5 Jan 2026', 'j F Y' => '5 January 2026', 'F j, Y' => 'January 5, 2026', 'd/m/Y' => '05/01/2026', 'Y-m-d' => '2026-01-05'] as $fmt => $expected) {
    Format::configure('UTC', $fmt);
    check('date format ' . $fmt, Format::date('2026-01-05 10:00:00') === $expected && isset(Format::DATE_FORMATS[$fmt]));
}
Format::configure('Not/AZone', 'bogus');
check('an invalid stored zone or format falls back to UTC and the default instead of failing', Format::timezone() === 'UTC' && Format::date('2026-10-21 12:00:00') === '21 Oct 2026');
check('empty or unreadable input gives an empty string, never an error', Format::date(null) === '' && Format::date('') === '' && Format::date('not a date') === '' && Format::isoDate(null) === '');
check('time zone validation', Format::isValidTimezone('Asia/Kolkata') && Format::isValidTimezone('UTC') && !Format::isValidTimezone('Mars/Base') && !Format::isValidTimezone('') && !Format::isValidTimezone('asia/kolkata '));
check('the format_date() and iso_date() helpers use the configured zone', (function () { Format::configure('Asia/Kolkata', 'Y-m-d'); $ok = format_date('2026-10-01 20:00:00') === '2026-10-02' && iso_date('2026-10-01 20:00:00') === '2026-10-02' && format_date('2026-10-01 20:00:00', 'H:i') === '01:30'; Format::configure('UTC', 'j M Y'); return $ok; })());

echo "Shipped migrations\n";
$sqlOk = true;
foreach (glob(CMS_ROOT . '/core/migrations/*.sql') as $f) {
    $sqlOk = $sqlOk && preg_match('/^\d{4}_[a-z0-9_]+\.sql$/', basename($f)) === 1 && !str_contains((string) file_get_contents($f), 'CREATE TABLE {p}');
}
check('names valid and CREATE TABLE IF NOT EXISTS used', $sqlOk);

$dsn = getenv('CMS_TEST_DB');
if ($dsn !== false && $dsn !== '') {
    echo "Database (prefix t_)\n";
    [$h, $p, $n, $u, $pw] = explode('|', $dsn) + [4 => ''];
    $dbCfg = ['host' => $h, 'port' => (int) $p, 'name' => $n, 'user' => $u, 'pass' => $pw];
    $db = new Database($dbCfg + ['prefix' => 't_']);
    foreach (['content_assets', 'content_tags', 'content_redirects', 'content_revisions', 'content', 'tags', 'code_assets', 'media', 'search_index', 'login_attempts', 'recovery_codes', 'users', 'settings', 'schema_migrations'] as $t) {
        $db->exec('DROP TABLE IF EXISTS {p}' . $t);
    }
    $m = new Migrator($db, CMS_ROOT . '/core/migrations');
    $all = ['0001_init', '0002_content', '0003_media', '0004_tags', '0005_content_seo', '0006_code_assets', '0007_convert_custom_code', '0008_search_index', '0009_content_paths', '0010_media_thumbnails'];
    check('pending before run', $m->pending() === $all);
    check('run applies all migrations in order (SQL and PHP)', $m->run() === $all);
    check('second run is a no-op', $m->run() === [] && $m->pending() === []);
    $s = new Settings($db);
    $s->set('k', 'v1');
    $s->set('k', 'v2');
    check('settings upsert', $s->get('k') === 'v2' && $s->get('missing', 'd') === 'd');
    $lim = new RateLimiter($db);
    check('not blocked initially', !$lim->blocked('1.2.3.4', 'admin'));
    for ($i = 0; $i < 5; $i++) {
        $lim->fail('1.2.3.4', 'admin');
    }
    check('blocked after 5 failures same ip+user', $lim->blocked('1.2.3.4', 'ADMIN'));
    check('other ip not blocked', !$lim->blocked('5.6.7.8', 'admin'));
    check('same ip other user not blocked yet', !$lim->blocked('1.2.3.4', 'someone'));
    $lim->clear('1.2.3.4', 'admin');
    check('cleared', !$lim->blocked('1.2.3.4', 'admin'));
    for ($i = 0; $i < 10; $i++) {
        $lim->fail('9.9.9.9', 'u' . $i);
    }
    check('blocked after 10 failures from one ip', $lim->blocked('9.9.9.9', 'new'));

    echo "Content (database + files)\n";
    $tmp = sys_get_temp_dir() . '/cms_content_' . bin2hex(random_bytes(4));
    mkdir($tmp . '/content/posts', 0755, true);
    $store = new ContentStore($tmp);
    $repo = new ContentRepository($db);
    $w = new ContentWriter($db, $store, new Sanitizer(), $repo);
    $mk = static function (array $over = []) use ($w): int {
        [$errors, $clean] = $w->validate($over + ['title' => 'Untitled item', 'type' => 'article', 'status' => 'published', 'body' => '<p>body</p>'], null);
        if ($errors !== []) {
            throw new RuntimeException('mk failed: ' . implode('; ', $errors));
        }
        return $w->create($clean);
    };
    $upd = static function (int $id, array $over) use ($w, $repo, $store): array {
        $row = $repo->find($id);
        $in = ['title' => $row['title'], 'type' => $row['type'], 'status' => $row['status'], 'visibility' => $row['visibility'],
            'slug' => $row['slug'], 'parent_id' => (string) $row['parent_id'], 'body' => (string) $store->read($id)] + [];
        [$errors, $clean] = $w->validate($over + $in, $row);
        if ($errors === []) {
            $w->update($id, $clean, 1);
        }
        return $errors;
    };

    $a = $mk(['title' => 'First Post', 'body' => '<p>Hello <script>x</script></p>']);
    $row = $repo->find($a);
    check('create stores row, sanitised body file and publish time', $row['slug'] === 'first-post' && $store->read($a) === '<p>Hello </p>' && $row['published_at'] !== null);
    check('excerpt auto-filled from body', $row['excerpt'] === 'Hello');
    $a2 = $mk(['title' => 'First Post']);
    check('duplicate title gets -2 slug', $repo->find($a2)['slug'] === 'first-post-2');
    $a3 = $mk(['title' => 'Admin']);
    check('reserved word as title falls to -2', $repo->find($a3)['slug'] === 'admin-2');
    [$e] = $w->validate(['title' => 'X', 'slug' => 'admin', 'type' => 'page'], null);
    check('reserved explicit slug rejected', $e !== []);
    [$e] = $w->validate(['title' => 'X', 'slug' => 'first-post', 'type' => 'page'], null);
    check('taken explicit slug rejected', $e !== []);
    [$e] = $w->validate(['title' => '', 'type' => 'nope', 'status' => 'x'], null);
    check('empty title, bad type and bad status rejected', count($e) >= 3);

    check('unchanged save creates no revision', $upd($a, []) === [] && $repo->revisions($a) === []);
    $upd($a, ['body' => '<p>second</p>']);
    $revs = $repo->revisions($a);
    check('body change keeps the previous body as a revision', count($revs) === 1 && $store->readRevision($a, (int) $revs[0]['id']) === '<p>Hello </p>' && $store->read($a) === '<p>second</p>');
    $upd($a, ['title' => 'First Post renamed']);
    check('title change also creates a revision; slug stays', count($repo->revisions($a)) === 2 && $repo->find($a)['slug'] === 'first-post');
    check('restore revision puts old body back and records what it replaced',
        $w->restoreRevision($a, (int) $revs[0]['id'], 1) === null && $store->read($a) === '<p>Hello </p>' && count($repo->revisions($a)) === 3);
    check('restoring an unknown revision fails cleanly', $w->restoreRevision($a, 999999, 1) !== null);
    for ($i = 0; $i < 25; $i++) {
        $upd($a, ['body' => '<p>edit ' . $i . '</p>']);
    }
    $kept = $repo->revisions($a);
    $files = glob($tmp . '/backups/revisions/' . $a . '/*.html');
    check('only the newest 20 revisions are kept (rows and files)', count($kept) === 20 && count($files) === 20);

    echo "Content: trees, trash, privacy\n";
    $root = $mk(['title' => 'Book', 'type' => 'book']);
    $c1 = $mk(['title' => 'Chapter 1', 'type' => 'book', 'parent_id' => (string) $root]);
    $c2 = $mk(['title' => 'Chapter 2', 'type' => 'book', 'parent_id' => (string) $root]);
    $c3 = $mk(['title' => 'Chapter 3', 'type' => 'book', 'parent_id' => (string) $root, 'status' => 'draft']);
    $g = $mk(['title' => 'Section 1.1', 'type' => 'book', 'parent_id' => (string) $c1]);
    check('children get increasing positions', array_map('intval', array_column($db->all('SELECT position FROM {p}content WHERE parent_id = ? ORDER BY id', [$root]), 'position')) === [1, 2, 3]);
    $outline = $repo->bookOutline();
    check('outline is depth-first with depths', array_column($outline, 'title') === ['Book', 'Chapter 1', 'Section 1.1', 'Chapter 2', 'Chapter 3'] && array_column($outline, 'depth') === [0, 1, 2, 1, 1]);
    check('outline can exclude a subtree', array_column($repo->bookOutline($c1), 'title') === ['Book', 'Chapter 2', 'Chapter 3']);
    check('ancestors root-first', array_column($repo->ancestors($g), 'title') === ['Book', 'Chapter 1']);
    check('descendants found', $repo->descendantIds($root) !== [] && count($repo->descendantIds($root)) === 4);
    check('parent cycle rejected', $upd($root, ['parent_id' => (string) $g]) !== []);
    check('self as parent rejected', $upd($c1, ['parent_id' => (string) $c1]) !== []);
    check('non-book type cannot keep children', $upd($root, ['type' => 'page']) !== []);
    [$e, $clean] = $w->validate(['title' => 'Page with parent', 'type' => 'page', 'parent_id' => (string) $root], null);
    check('parent ignored for non-book types', $e === [] && $clean['parent_id'] === null);
    check('public children exclude drafts', array_column($repo->publicChildren($root), 'title') === ['Chapter 1', 'Chapter 2']);
    $n = $repo->publicNeighbors($repo->find($c1));
    check('neighbours among public siblings', $n['prev'] === null && $n['next']['title'] === 'Chapter 2');
    $w->move($c2, 'up');
    check('move up swaps order', array_column($repo->publicChildren($root), 'title') === ['Chapter 2', 'Chapter 1']);
    $w->move($c2, 'up');
    check('move beyond the edge is a no-op', array_column($repo->publicChildren($root), 'title') === ['Chapter 2', 'Chapter 1']);

    check('item with children cannot be trashed', $w->trash($root) !== null && $w->trash($c1) !== null);
    check('leaf can be trashed', $w->trash($g) === null && $repo->find($g)['deleted_at'] !== null);
    check('trashed item hidden from address lookup but its address stays taken', $repo->findByPath('book/chapter-1/section-1-1') === null && $repo->pathTaken('book/chapter-1/section-1-1'));
    check('trash then parent chain', $w->trash($c1) === null && $w->trash($c2) === null && $w->trash($c3) === null && $w->trash($root) === null);
    check('cannot restore a child before its parent', $w->restore($g) !== null);
    check('restore parent first, then child', $w->restore($root) === null && $w->restore($c1) === null && $w->restore($g) === null);
    check('purge refuses items not in the trash', $w->purge($root) !== null);
    $w->trash($g);
    $before = $store->exists($g);
    check('purge removes the row but archives the files', $w->purge($g) === null && $repo->find($g) === null && $before && !$store->exists($g) && glob($tmp . '/backups/deleted/' . $g . '-*/body.html') !== []);
    $pp = $mk(['title' => 'Purge parent', 'type' => 'book']);
    $pk = $mk(['title' => 'Purge kid', 'type' => 'book', 'parent_id' => (string) $pp]);
    $w->trash($pk);
    $w->trash($pp);
    check('purge refused while a trashed child exists, then allowed bottom-up', $w->purge($pp) !== null && $w->purge($pk) === null && $w->purge($pp) === null);

    $priv = $mk(['title' => 'Secret', 'visibility' => 'private', 'view_password' => 'hunter2hunter2']);
    $prow = $repo->find($priv);
    check('private page stores a password hash, not the password', $prow['view_password_hash'] !== null && password_verify('hunter2hunter2', $prow['view_password_hash']) && !str_contains($prow['view_password_hash'], 'hunter'));
    [$e] = $w->validate(['title' => 'S', 'type' => 'page', 'visibility' => 'private'], null);
    check('private without password rejected', $e !== []);
    [$e] = $w->validate(['title' => 'S', 'type' => 'page', 'visibility' => 'private', 'view_password' => 'short'], null);
    check('short page password rejected', $e !== []);
    check('keeping the password when editing a private page', $upd($priv, ['body' => '<p>new</p>']) === [] && password_verify('hunter2hunter2', $repo->find($priv)['view_password_hash']));
    check('switching to public clears the hash', $upd($priv, ['visibility' => 'public']) === [] && $repo->find($priv)['view_password_hash'] === null);
    $upd($priv, ['visibility' => 'private', 'view_password' => 'another-secret-pw']);
    $titles = array_column($repo->latestPublicArticles(1, 100)['rows'], 'title');
    check('private and draft items never appear in public listings', !in_array('Secret', $titles, true));

    echo "Content: lists, bulk, integrity\n";
    for ($i = 1; $i <= 25; $i++) {
        $mk(['title' => 'Listing item ' . $i, 'status' => $i % 2 ? 'published' : 'draft']);
    }
    $p1 = $repo->adminList(['q' => 'Listing item'], 1, 10);
    $p3 = $repo->adminList(['q' => 'Listing item'], 3, 10);
    check('pagination totals and page sizes', $p1['total'] === 25 && count($p1['rows']) === 10 && count($p3['rows']) === 5);
    check('status filter', $repo->adminList(['q' => 'Listing item', 'status' => 'draft'], 1, 50)['total'] === 12);
    check('LIKE wildcards in the search are literal', $repo->adminList(['q' => '%'], 1, 10)['total'] === 0 && $repo->adminList(['q' => '_'], 1, 10)['total'] === 0);
    check('type filter (trashed items excluded)', $repo->adminList(['type' => 'book'], 1, 50)['total'] === 2 && $repo->adminList(['type' => 'book', 'status' => 'trash'], 1, 50)['total'] === 2);
    $pub = $repo->latestPublicArticles(1, 5);
    check('public list: only published public articles, newest first, paged', $pub['total'] >= 13 && count($pub['rows']) === 5);
    $ids = array_column($db->all("SELECT id FROM {p}content WHERE title LIKE 'Listing item%' AND status = 'draft' LIMIT 3"), 'id');
    check('bulk publish sets status and published_at', $w->setStatus($ids, 'published') === 3 && $db->value('SELECT COUNT(*) FROM {p}content WHERE id IN (' . implode(',', $ids) . ') AND published_at IS NOT NULL') == 3);
    check('bulk ignores trashed items', $w->setStatus([$g], 'published') === 0);
    $counts = $repo->counts();
    check('counts add up', $counts['published'] > 0 && $counts['draft'] > 0 && $counts['trash'] >= 1);

    $victim = $mk(['title' => 'Will lose its file']);
    unlink($store->postPath($victim));
    file_put_contents($tmp . '/content/posts/55555.html', '<p>stray</p>');
    $rep = $repo->integrity($store);
    check('integrity reports missing body file', in_array($victim, array_map('intval', array_column($rep['missing'], 'id')), true));
    check('integrity reports orphan file without touching it', $rep['orphans'] === [55555] && is_file($tmp . '/content/posts/55555.html'));
    $upd($victim, ['body' => '<p>recreated</p>']);
    check('saving an item with a missing file recreates it and records the gap', $store->read($victim) === '<p>recreated</p>' && $repo->revisions($victim)[0]['note'] === 'body file was missing');
    exec('rm -rf ' . escapeshellarg($tmp));


    echo "Migrations: PHP migrations next to SQL\n";
    $mdir = sys_get_temp_dir() . '/cms_mig_' . bin2hex(random_bytes(4));
    mkdir($mdir);
    file_put_contents($mdir . '/0001_make.sql', "CREATE TABLE IF NOT EXISTS {p}mig_probe (id INT NOT NULL, note VARCHAR(20) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB;\n");
    file_put_contents($mdir . '/0002_fill.php', "<?php\nreturn static function (Cms\\Database \$db): void { \$db->run('INSERT INTO {p}mig_probe (id, note) VALUES (1, ?)', ['from php']); };\n");
    $db->exec('DROP TABLE IF EXISTS {p}mig_probe');
    $db->exec('DELETE FROM {p}schema_migrations WHERE version IN (\'0001_make\', \'0002_fill\')');
    $mm = new Migrator($db, $mdir);
    check('PHP and SQL migrations are listed together in order', $mm->available() === ['0001_make', '0002_fill']);
    check('both kinds run, in order', $mm->run() === ['0001_make', '0002_fill'] && $db->value('SELECT note FROM {p}mig_probe WHERE id = 1') === 'from php');
    check('applied migrations are not run again', $mm->run() === []);
    file_put_contents($mdir . '/0003_dup.sql', 'SELECT 1;');
    file_put_contents($mdir . '/0003_dup.php', "<?php\nreturn static function (): void {};\n");
    $threw = false;
    try { $mm->pending(); } catch (RuntimeException $ex) { $threw = str_contains($ex->getMessage(), 'both'); }
    check('the same version as .sql and .php is refused', $threw);
    unlink($mdir . '/0003_dup.sql');
    file_put_contents($mdir . '/0003_dup.php', "<?php\nreturn 5;\n");
    $threw = false;
    try { $mm->run(); } catch (RuntimeException $ex) { $threw = str_contains($ex->getMessage(), 'must return a function'); }
    check('a PHP migration that returns no function fails and is not recorded', $threw && $db->value("SELECT COUNT(*) FROM {p}schema_migrations WHERE version = '0003_dup'") == 0);
    $db->exec('DROP TABLE IF EXISTS {p}mig_probe');
    $db->exec('DELETE FROM {p}schema_migrations WHERE version IN (\'0001_make\', \'0002_fill\')');
    exec('rm -rf ' . escapeshellarg($mdir));

    echo "Media library\n";
    $udir = sys_get_temp_dir() . '/cms_user_' . bin2hex(random_bytes(4));
    $up = $udir . '/uploads';
    mkdir($up, 0755, true);
    $fx = CMS_ROOT . '/tests/fixtures/';
    $mw = new MediaWriter($db, $up, 100000);
    $mrepo = new MediaRepository($db);
    $take = static function (string $name) use ($fx): string { $t = tempnam(sys_get_temp_dir(), 'up_'); copy($fx . $name, $t); return $t; };
    $mv = static fn (string $from, string $to): bool => rename($from, $to);
    $imp = static fn (string $tmp, string $clientName) => $mw->import($tmp, $clientName, 1, $mv);
    $ids = [];
    foreach (['tiny.png' => 'image/png', 'tiny.jpg' => 'image/jpeg', 'tiny.gif' => 'image/gif', 'tiny.webp' => 'image/webp', 'tiny.pdf' => 'application/pdf'] as $f => $mime) {
        $r = $imp($take($f), $f);
        $ids[$f] = $r['id'];
        $row = $r['ok'] ? $mrepo->find($r['id']) : null;
        check('accepts ' . $f, $r['ok'] && $row['mime'] === $mime && is_file($up . '/' . $row['path']) && preg_match('#^\d{4}/\d{2}/[0-9a-f]{20}\.(png|jpg|gif|webp|pdf)$#', $row['path']) === 1, $r['error']);
    }
    $r1 = $mrepo->find($ids['tiny.png']);
    check('image dimensions are recorded; PDFs have none', (int) $r1['width'] === 1 && (int) $r1['height'] === 1 && $mrepo->find($ids['tiny.pdf'])['width'] === null);
    check('stored files are world-readable (served by the web server)', (fileperms($up . '/' . $r1['path']) & 0777) === 0644);
    $again = $imp($take('tiny.png'), 'tiny.png');
    check('the same file uploaded twice gets two random names', $again['ok'] && $mrepo->find($again['id'])['path'] !== $r1['path']);

    $t = tempnam(sys_get_temp_dir(), 'up_'); file_put_contents($t, "<?php echo 'pwned'; ?>");
    $r = $imp($t, 'shell.php.png');
    check('a PHP script renamed to .png is rejected (type comes from content)', !$r['ok'] && str_contains($r['error'], 'not an allowed file type'));
    $r = $imp($take('tiny.png'), 'shell.php');
    $row = $r['ok'] ? $mrepo->find($r['id']) : null;
    check('a real PNG named .php is stored with a .png extension and random name', $r['ok'] && str_ends_with($row['path'], '.png') && !str_contains($row['path'], 'shell') && $row['original_name'] === 'shell.php');
    $t = tempnam(sys_get_temp_dir(), 'up_'); file_put_contents($t, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
    check('SVG is rejected', !$imp($t, 'logo.svg')['ok']);
    $t = tempnam(sys_get_temp_dir(), 'up_'); file_put_contents($t, '<html><script>alert(1)</script></html>');
    check('HTML is rejected', !$imp($t, 'page.png')['ok']);
    $t = tempnam(sys_get_temp_dir(), 'up_'); file_put_contents($t, 'just text pretending to be a pdf');
    check('text posing as PDF is rejected', !$imp($t, 'doc.pdf')['ok']);
    $t = tempnam(sys_get_temp_dir(), 'up_');
    $r = $imp($t, 'empty.png');
    check('empty file is rejected', !$r['ok'] && str_contains($r['error'], 'empty'));
    $t = tempnam(sys_get_temp_dir(), 'up_'); file_put_contents($t, substr(file_get_contents($fx . 'tiny.png'), 0, 12));
    check('an image whose header is cut off is rejected (only the header is validated, not a full decode)', !$imp($t, 'cut.png')['ok']);
    $r = $imp($take('huge-dimensions.png'), 'huge.png');
    check('absurd pixel dimensions are rejected', !$r['ok'] && str_contains($r['error'], 'too large in pixels'));
    $small = new MediaWriter($db, $up, 10);
    $r = $small->import($take('tiny.png'), 'big.png', 1, $mv);
    check('over the size limit is rejected', !$r['ok'] && str_contains($r['error'], 'larger than the limit'));
    check('a missing temp file is handled', !$mw->import('/nonexistent/file', 'x.png', 1, $mv)['ok']);
    $rc = $imp($take('tiny.png'), "a\x00b\x1fc.png");
    check('control characters are removed from the stored original file name', $rc['ok'] && $mrepo->find($rc['id'])['original_name'] === 'abc.png');
    $before = (int) $db->value('SELECT COUNT(*) FROM {p}media');
    $r = $mw->import($take('tiny.png'), 'fail.png', 1, static fn (string $a, string $b): bool => false);
    check('a failed move leaves no database row', !$r['ok'] && (int) $db->value('SELECT COUNT(*) FROM {p}media') === $before);

    $mw->updateAlt($ids['tiny.png'], '  A tiny dot  ');
    check('alt text saved trimmed; blank clears it', $mrepo->find($ids['tiny.png'])['alt_text'] === 'A tiny dot' && ($mw->updateAlt($ids['tiny.png'], ' ') ?? true) && $mrepo->find($ids['tiny.png'])['alt_text'] === null);
    $imgs = array_column($mrepo->images(50), 'id');
    check('images() lists images only', in_array($ids['tiny.png'], array_map('intval', $imgs), true) && !in_array($ids['tiny.pdf'], array_map('intval', $imgs), true));
    check('media list: pagination and literal wildcard search', $mrepo->list(1, 2)['total'] >= 6 && count($mrepo->list(1, 2)['rows']) === 2 && $mrepo->list(1, 10, '%')['total'] === 0 && $mrepo->list(1, 10, 'tiny.pdf')['total'] === 1);
    $settingsM = new Settings($db);
    $settingsM->set('site_logo_media_id', (string) $ids['tiny.gif']);
    check('a file used as the logo cannot be deleted', $mw->delete($ids['tiny.gif']) !== null && $mrepo->find($ids['tiny.gif']) !== null && in_array('the site logo', $mw->usage($ids['tiny.gif']), true));
    $site = SiteInfo::load($settingsM, $mrepo);
    check('SiteInfo exposes the logo URL', $site['logo'] === '/user/uploads/' . $mrepo->find($ids['tiny.gif'])['path']);
    $settingsM->set('site_logo_media_id', '');
    $archivedPath = $up . '/' . $mrepo->find($ids['tiny.gif'])['path'];
    check('delete removes the record and archives the file', $mw->delete($ids['tiny.gif']) === null && $mrepo->find($ids['tiny.gif']) === null && !is_file($archivedPath) && glob($udir . '/backups/deleted/media-' . $ids['tiny.gif'] . '-*/*.gif') !== [] && glob($udir . '/backups/deleted/media-' . $ids['tiny.gif'] . '-*/meta.json') !== []);
    check('deleting an unknown id is handled', $mw->delete(987654) !== null);
    unlink($up . '/' . $mrepo->find($ids['tiny.jpg'])['path']);
    @mkdir($up . '/2026/10', 0755, true);
    file_put_contents($up . '/2026/10/stray.png', 'x');
    $mi = $mrepo->integrity($up);
    check('media integrity: missing file and orphan file reported, nothing touched', in_array($ids['tiny.jpg'], array_map('intval', array_column($mi['missing'], 'id')), true) && in_array('2026/10/stray.png', $mi['orphans'], true) && is_file($up . '/2026/10/stray.png'));
    check('upload error messages', str_contains(MediaWriter::uploadError(UPLOAD_ERR_INI_SIZE), 'upload_max_filesize') && MediaWriter::formatBytes(1536) === '2 KB' && MediaWriter::formatBytes(5242880) === '5 MB');


    echo "Thumbnails\n";
    $gdOn = (new Thumbnailer())->available();
    if (!$gdOn) {
        echo "  (skipped: PHP GD is not available in this environment)\n";
    } else {
        $gdFile = static function (string $kind, int $w, int $h): string {
            $im = imagecreatetruecolor($w, $h);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagefilledrectangle($im, (int) ($w * 0.25), (int) ($h * 0.25), (int) ($w * 0.75), (int) ($h * 0.75), imagecolorallocate($im, 200, 30, 30));
            $t = tempnam(sys_get_temp_dir(), 'gd_');
            match ($kind) { 'jpg' => imagejpeg($im, $t, 90), 'png' => imagepng($im, $t), 'gif' => imagegif($im, $t), default => imagewebp($im, $t, 80) };
            return $t;
        };
        $base = sys_get_temp_dir() . '/cms_thumbs_' . bin2hex(random_bytes(4));
        $th = new Thumbnailer();
        $r = $th->create($gdFile('jpg', 1200, 800), 'image/jpeg', $base . '/a');
        check('JPEG: thumbnail fits 400px, keeps proportions, stays JPEG', $r['status'] === 'ok' && str_ends_with($r['file'], '.jpg') && $r['width'] === 400 && $r['height'] === 267 && getimagesize($r['file'])[0] === 400 && (fileperms($r['file']) & 0777) === 0644);
        $r = $th->create($gdFile('png', 900, 900), 'image/png', $base . '/b');
        $pngThumb = imagecreatefrompng($r['file']);
        check('PNG: thumbnail is PNG and keeps transparency', $r['status'] === 'ok' && str_ends_with($r['file'], '.png') && $r['width'] === 400 && $r['height'] === 400 && ((imagecolorat($pngThumb, 0, 0) >> 24) & 0x7F) === 127);
        $r = $th->create($gdFile('gif', 600, 300), 'image/gif', $base . '/c');
        check('GIF: becomes a PNG thumbnail', $r['status'] === 'ok' && str_ends_with($r['file'], '.png') && $r['width'] === 400 && $r['height'] === 200);
        $r = $th->create($gdFile('webp', 800, 800), 'image/webp', $base . '/d');
        check('WebP: stays WebP', $r['status'] === 'ok' && str_ends_with($r['file'], '.webp') && $r['width'] === 400);
        $r = $th->create($gdFile('jpg', 300, 200), 'image/jpeg', $base . '/e');
        check('an image that is already small gets no thumbnail', $r['status'] === 'small' && !is_file($base . '/e.jpg'));
        $r = $th->create($fx . 'tiny.png', 'image/png', $base . '/f');
        check('a 1x1 image gets none either', $r['status'] === 'small');
        // JPEG with an EXIF orientation flag (as phones write): a 1200x600 photo flagged "rotate 90 degrees" must come out portrait.
        $plain = file_get_contents($gdFile('jpg', 1200, 600));
        $tiff = "II*\x00\x08\x00\x00\x00" . pack('v', 1) . pack('vvVv', 0x0112, 3, 1, 6) . "\x00\x00" . pack('V', 0);
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
        $rot = tempnam(sys_get_temp_dir(), 'gd_');
        file_put_contents($rot, substr($plain, 0, 2) . $app1 . substr($plain, 2));
        $r = $th->create($rot, 'image/jpeg', $base . '/g');
        check('EXIF orientation is applied (landscape pixels with a rotate flag give a portrait thumbnail)', !function_exists('exif_read_data') || ($r['status'] === 'ok' && $r['height'] === 400 && $r['width'] === 200), $r);
        $cut = tempnam(sys_get_temp_dir(), 'gd_');
        file_put_contents($cut, substr($plain, 0, 700));
        $r = $th->create($cut, 'image/jpeg', $base . '/h');
        check('a damaged image never throws; it reports ok or failed', in_array($r['status'], ['ok', 'failed'], true));
        check('unsupported types and a disabled thumbnailer are reported as skipped', $th->create($fx . 'tiny.pdf', 'application/pdf', $base . '/i')['status'] === 'skipped' && (new Thumbnailer(false))->create($gdFile('jpg', 900, 900), 'image/jpeg', $base . '/j')['status'] === 'skipped' && !(new Thumbnailer(false))->available());
        $old = ini_get('memory_limit');
        ini_set('memory_limit', '64M');
        check('memory guard: huge images are refused, ordinary ones are fine', !Thumbnailer::memoryOk(10000, 10000) && Thumbnailer::memoryOk(1000, 1000));
        check('a huge image is skipped instead of crashing', $th->create($fx . 'huge-dimensions.png', 'image/png', $base . '/k')['status'] === 'skipped');
        ini_set('memory_limit', $old);
        exec('rm -rf ' . escapeshellarg($base));

        // ---- through the media library ----
        $big = $gdFile('jpg', 1200, 800);
        $r = $mw->import($big, 'holiday.jpg', 1, $mv);
        $row = $mrepo->find($r['id']);
        check('upload creates a thumbnail beside the original and records it', $r['ok'] && $row['thumb_status'] === 'ok' && preg_match('#^\d{4}/\d{2}/thumbs/[0-9a-f]{20}\.jpg$#', $row['thumb_path']) === 1 && is_file($up . '/' . $row['thumb_path']) && (int) $row['thumb_width'] === 400 && (int) $row['thumb_height'] === 267);
        check('grids use the thumbnail URL; small images fall back to the original', MediaRepository::thumbUrl($row) === '/user/uploads/' . $row['thumb_path'] && MediaRepository::thumbUrl($mrepo->find($ids['tiny.png'])) === '/user/uploads/' . $mrepo->find($ids['tiny.png'])['path']);
        check('small images are recorded as "small"; PDFs are not touched', $mrepo->find($ids['tiny.png'])['thumb_status'] === 'small' && $mrepo->find($ids['tiny.pdf'])['thumb_status'] === null && $mrepo->find($ids['tiny.pdf'])['thumb_path'] === null);
        $noGd = new MediaWriter($db, $up, 100000, new Thumbnailer(false));
        $r2 = $noGd->import($gdFile('jpg', 1000, 1000), 'nogd.jpg', 1, $mv);
        check('without GD the upload still works and the image stays "not attempted"', $r2['ok'] && $mrepo->find($r2['id'])['thumb_status'] === null && !$noGd->thumbnailsAvailable());
        check('"not attempted" images are counted for the Generate button', $mrepo->missingThumbnailCount() >= 1);
        $bad = $mw->import($gdFile('png', 1000, 1000), 'ghost.png', 1, $mv);
        unlink($up . '/' . $mrepo->find($bad['id'])['path']);
        $db->run('UPDATE {p}media SET thumb_status = NULL, thumb_path = NULL WHERE id = ?', [$bad['id']]);
        $partial = $mw->generateMissing(0, microtime(true) - 1);
        check('backfill stops at the deadline and reports where to resume', $partial['next'] !== null && ($partial['made'] + $partial['skipped']) === 1);
        $rest = $mw->generateMissing((int) $partial['next'], microtime(true) + 30);
        check('backfill resumes and finishes; every image now has a status', $rest['next'] === null && $mrepo->missingThumbnailCount() === 0);
        check('backfill created the thumbnail for the GD-less upload and marked the missing source as failed', $mrepo->find($r2['id'])['thumb_status'] === 'ok' && is_file($up . '/' . $mrepo->find($r2['id'])['thumb_path']) && $mrepo->find($bad['id'])['thumb_status'] === 'failed');
        $tp = $up . '/' . $row['thumb_path'];
        unlink($tp);
        $mi2 = $mrepo->integrity($up);
        check('integrity reports a recorded thumbnail that is missing', in_array($r['id'], array_map('intval', array_column($mi2['missing_thumbs'], 'id')), true));
        file_put_contents($up . '/' . dirname($row['thumb_path']) . '/stray-thumb.jpg', 'x');
        check('integrity reports a stray file in thumbs/ and does not touch it', in_array(dirname($row['thumb_path']) . '/stray-thumb.jpg', $mrepo->integrity($up)['orphans'], true) && is_file($up . '/' . dirname($row['thumb_path']) . '/stray-thumb.jpg'));
        check('"Create again" rebuilds the thumbnail', $mw->makeThumbnail($r['id'], $row['path'], $row['mime']) === 'ok' && is_file($tp));
        check('deleting the image archives its thumbnail with it', $mw->delete($r['id']) === null && !is_file($tp) && glob($udir . '/backups/deleted/media-' . $r['id'] . '-*/thumb-*') !== []);
    }

    echo "Code assets (CSS/JS library)\n";
    $cstore = new CodeAssetStore($udir);
    $crepo = new CodeAssetRepository($db);
    $cw = new CodeAssetWriter($db, $cstore, $crepo);
    $val = static fn (string $kind, string $name, string $code, string $order = '', ?int $except = null): array => $cw->validate($kind, $name, '', $order, $code, $except)[0];
    check('validation: kind, name, order, code', $val('x', 'a', 'a{}') !== [] && $val('css', '', 'a{}') !== [] && $val('css', 'ok', 'a{}', '12a') !== [] && $val('css', 'ok', 'a{}', '10000') !== [] && $val('css', 'ok', '   ') !== [] && $val('css', 'ok', 'a{}', '5') === []);
    check('validation: pasted <style>/<script> wrappers, PHP and huge code rejected', $val('css', 'w', '<style>a{}</style>') !== [] && $val('js', 'w', '<script>alert(1)</script>') !== [] && $val('js', 'w', "<?php echo 1; ?>") !== [] && $val('css', 'w', str_repeat('a', 200001)) !== []);
    check('validation: a script may contain the text "</script>" inside a string', $val('js', 'w', 'var s = "<\/script>";') === []);
    [, $c1] = $cw->validate('css', 'Table styles', 'Wide tables', '20', "table { width: 100%; }\n", null);
    $cid = $cw->create($c1);
    $crow = $crepo->find($cid);
    check('create writes the file with a safe name and records hash and size', $crow['file'] === $cid . '-table-styles.css' && $cstore->read('css', $crow['file']) === $c1['code'] && $crow['version_hash'] === substr(sha1($c1['code']), 0, 10) && (int) $crow['size'] === strlen($c1['code']) && (fileperms($cstore->path('css', $crow['file'])) & 0777) === 0644);
    check('asset URLs are versioned', CodeAssetRepository::url($crow) === '/user/assets/css/' . $cid . '-table-styles.css?v=' . $crow['version_hash']);
    check('duplicate name within a kind is refused, the same name for the other kind is fine', $val('css', 'Table styles', 'a{}') !== [] && $val('js', 'Table styles', 'var a=1;') === [] && $val('css', 'Table styles', 'a{}', '', $cid) === []);
    [, $c2] = $cw->validate('js', 'अनुच्छेद', '', '5', 'console.log(1);', null);
    $jid = $cw->create($c2);
    check('a non-Latin name still gets an ASCII file name', $crepo->find($jid)['file'] === $jid . '-asset.js');
    $cw->update($cid, ['name' => 'Table styles', 'description' => 'v2', 'load_order' => 20, 'code' => "table { width: 90%; }\n"]);
    $v1 = $cstore->versions($cid, 'css');
    check('changing the code keeps the previous file as a version and changes the hash', count($v1) === 1 && $cstore->readVersion($cid, 'css', $v1[0]['name']) === $c1['code'] && $crepo->find($cid)['version_hash'] !== $crow['version_hash']);
    $cw->update($cid, ['name' => 'Table styles', 'description' => 'only metadata', 'load_order' => 30, 'code' => "table { width: 90%; }\n"]);
    check('saving without code changes adds no version', count($cstore->versions($cid, 'css')) === 1 && (int) $crepo->find($cid)['load_order'] === 30);
    check('restore puts the old code back and records what it replaced', $cw->restoreVersion($cid, $v1[0]['name']) === null && $cstore->read('css', $crow['file']) === $c1['code'] && count($cstore->versions($cid, 'css')) === 2);
    check('restore refuses bad version names', $cw->restoreVersion($cid, '../../x') !== null && $cw->restoreVersion($cid, '20260101-000000-abcd.js') !== null && $cstore->readVersion($cid, 'css', '../../content/posts/1.html') === null);
    for ($i = 0; $i < 14; $i++) { $cw->update($cid, ['name' => 'Table styles', 'description' => '', 'load_order' => 30, 'code' => 'a{x:' . $i . '}']); }
    check('only the newest 10 versions are kept', count($cstore->versions($cid, 'css')) === 10 && count(glob($udir . '/backups/assets/' . $cid . '/*.css')) === 10);

    $cmt = $mk(['title' => 'Linked page']);
    $post = ['asset_ids' => [(string) $cid, (string) $jid]];
    $row = $repo->find($cmt);
    $in = ['title' => 'Linked page', 'type' => 'article', 'status' => 'published', 'visibility' => 'public', 'slug' => $row['slug'], 'body' => '<p>x</p>'] + $post;
    [$e, $clean] = $w->validate($in, $row);
    $w->update($cmt, $clean, 1);
    $urls = $crepo->urlsForContent($cmt);
    $linked = $crepo->linkedIds($cmt);
    sort($linked);
    check('linking assets to content: ids stored, URLs split by kind', $e === [] && count($urls['css']) === 1 && count($urls['js']) === 1 && str_contains($urls['css'][0], '/user/assets/css/') && str_contains($urls['js'][0], '/user/assets/js/') && $linked === [min($cid, $jid), max($cid, $jid)]);
    [, $c3] = $cw->validate('css', 'Early styles', '', '1', 'p{margin:0}', null);
    $eid = $cw->create($c3);
    $clean2 = $w->validate($in, $row)[1];
    $clean2['asset_ids'] = [$cid, $eid];
    $w->update($cmt, $clean2, 1);
    $urls = $crepo->urlsForContent($cmt);
    check('assets load in load-order (lower first)', count($urls['css']) === 2 && str_contains($urls['css'][0], $eid . '-early-styles') && str_contains($urls['css'][1], $cid . '-table-styles') && $urls['js'] === []);
    [$e] = $w->validate(array_merge($in, ['asset_ids' => ['999999']]), $row);
    check('linking an unknown asset is rejected', $e !== []);
    check('usage counts and usedBy', ($crepo->usageCounts()[$cid] ?? 0) === 1 && array_column($crepo->usedBy($cid), 'id') === [$cmt]);
    $cw->delete($jid);
    check('deleting an asset removes its row, unlinks it everywhere, and archives the file and versions', $crepo->find($jid) === null && $db->value('SELECT COUNT(*) FROM {p}content_assets WHERE asset_id = ?', [$jid]) == 0 && glob($udir . '/backups/deleted/asset-' . $jid . '-*/*.js') !== [] && !$cstore->exists('js', $jid . '-asset.js'));
    unlink($cstore->path('css', $crepo->find($eid)['file']));
    file_put_contents($cstore->path('js', '777-stray.js'), 'x');
    $ci = $crepo->integrity($cstore);
    check('code asset integrity: missing and orphan files reported', array_column($ci['missing'], 'id') === [$eid] && $ci['orphans'] === ['js/777-stray.js'] && is_file($cstore->path('js', '777-stray.js')));

    echo "Conversion of per-page custom CSS/JS\n";
    $old1 = $mk(['title' => 'Old page with code']);
    $old2 = $mk(['title' => 'Old page with bad code']);
    $db->run("UPDATE {p}content SET custom_css = ?, custom_js = ? WHERE id = ?", ["body{color:red}", "console.log('hi');", $old1]);
    $db->run("UPDATE {p}content SET custom_css = ? WHERE id = ?", ["<?php system('x'); ?>", $old2]);
    $cstore2 = new CodeAssetStore($udir . '/conv');
    $logf = CMS_ROOT . '/user/logs/app.log';
    $logPre = is_file($logf);
    $res = (new CodeAssetConverter())->run($db, $cstore2); // logs the skipped item
    if (!$logPre && is_file($logf)) { unlink($logf); } // tests must not leave files in the project
    $u1 = (new CodeAssetRepository($db))->urlsForContent($old1);
    check('conversion creates and links a stylesheet and a script', $res['converted'] === 2 && count($u1['css']) === 1 && count($u1['js']) === 1 && str_contains((string) $cstore2->read('css', $db->value("SELECT file FROM {p}code_assets WHERE kind = 'css' AND name LIKE ?", ['%#' . $old1])), 'color:red'));
    check('code that fails validation is skipped, not lost', $res['skipped'] === 1 && (new CodeAssetRepository($db))->urlsForContent($old2) === ['css' => [], 'js' => []] && str_contains((string) $db->value('SELECT custom_css FROM {p}content WHERE id = ?', [$old2]), 'system'));
    $res2 = (new CodeAssetConverter())->run($db, $cstore2);
    if (!$logPre && is_file($logf)) { unlink($logf); }
    check('running the conversion again does not duplicate assets', $res2['converted'] === 0 && (int) $db->value("SELECT COUNT(*) FROM {p}code_assets WHERE name LIKE ?", ['Imported:%#' . $old1]) === 2);
    $db->run('UPDATE {p}content SET custom_css = NULL, custom_js = NULL');



    echo "Hierarchical addresses\n";
    $b1 = $mk(['title' => 'Law Book', 'type' => 'book']);
    $b2 = $mk(['title' => 'Other Book', 'type' => 'book']);
    $h1 = $mk(['title' => 'Part One', 'type' => 'book', 'parent_id' => (string) $b1]);
    $h2 = $mk(['title' => 'Article 1', 'type' => 'book', 'parent_id' => (string) $h1]);
    $P = static fn (int $id): string => (string) $repo->find($id)['path'];
    check('children get parent/child/grandchild addresses; top level keeps just the slug', $P($b1) === 'law-book' && $P($h1) === 'law-book/part-one' && $P($h2) === 'law-book/part-one/article-1' && $repo->find($h2)['slug'] === 'article-1');
    $i1 = $mk(['title' => 'Introduction', 'type' => 'book', 'parent_id' => (string) $b1]);
    $i2 = $mk(['title' => 'Introduction', 'type' => 'book', 'parent_id' => (string) $b2]);
    $i3 = $mk(['title' => 'Introduction', 'type' => 'book', 'parent_id' => (string) $b1]);
    check('the same slug is fine under different parents; within one parent it gets -2', $P($i1) === 'law-book/introduction' && $P($i2) === 'other-book/introduction' && $P($i3) === 'law-book/introduction-2');
    $rootIntro = $mk(['title' => 'Introduction', 'type' => 'page']);
    $childAdmin = $mk(['title' => 'Admin', 'type' => 'book', 'parent_id' => (string) $b1]);
    check('a top-level item may share a slug with a child; reserved names are only reserved at the top level', $P($rootIntro) === 'introduction' && $P($childAdmin) === 'law-book/admin');
    [$e] = $w->validate(['title' => 'x', 'type' => 'book', 'parent_id' => (string) $b1, 'slug' => 'part-one'], null);
    [$e2] = $w->validate(['title' => 'x', 'type' => 'book', 'parent_id' => (string) $b2, 'slug' => 'part-one'], null);
    check('an explicit slug already used under the same parent is rejected, under another parent accepted', $e !== [] && str_contains($e[0], '/law-book/part-one') && $e2 === []);
    [$e, $c] = $w->validate(['title' => 'x', 'type' => 'article', 'parent_id' => (string) $b1], null);
    check('articles and pages ignore any parent', $e === [] && $c['parent_id'] === null && !str_contains($c['path'], '/'));
    check('address lookup is case-insensitive and finds only non-trashed items', $repo->findByPath('LAW-BOOK/Part-One')['id'] == $h1 && $repo->findByPath('nope') === null);
    check('relation rows used by themes carry the address (children, neighbours, ancestors, list)', isset($repo->publicChildren($b1)[0]['path']) && isset($repo->ancestors($h2)[0]['path']) && isset($repo->latestPublicArticles(1, 1)['rows'][0]['path']) && isset($repo->bookOutline()[0]['path']));

    $upd($b1, ['slug' => 'law-handbook']);
    check('renaming a parent re-addresses the whole subtree', $P($b1) === 'law-handbook' && $P($h1) === 'law-handbook/part-one' && $P($h2) === 'law-handbook/part-one/article-1' && $P($i1) === 'law-handbook/introduction');
    check('every old address now redirects to the current one', $repo->redirectTarget('law-book') === 'law-handbook' && $repo->redirectTarget('law-book/part-one') === 'law-handbook/part-one' && $repo->redirectTarget('law-book/part-one/article-1') === 'law-handbook/part-one/article-1');
    check('a redirect never points at a draft or trashed item', ($upd($h2, ['status' => 'draft']) === [] && $repo->redirectTarget('law-book/part-one/article-1') === null) && ($upd($h2, ['status' => 'published']) === [] && $repo->redirectTarget('law-book/part-one/article-1') !== null));
    $upd($h1, ['parent_id' => (string) $b2]);
    check('moving a page to another parent re-addresses it and its children', $P($h1) === 'other-book/part-one' && $P($h2) === 'other-book/part-one/article-1' && $repo->redirectTarget('law-handbook/part-one/article-1') === 'other-book/part-one/article-1');
    check('moving into a parent that already has that slug is refused', $upd($i1, ['parent_id' => (string) $b2]) !== [] && $P($i1) === 'law-handbook/introduction');
    $newTop = $mk(['title' => 'Old name reused', 'type' => 'page', 'slug' => 'law-book']);
    check('a live address wins over an old redirect', $P($newTop) === 'law-book' && $repo->redirectTarget('law-book') === null && $repo->findByPath('law-book')['id'] == $newTop);
    $rv = $repo->revisions($h1);
    $upd($h1, ['body' => '<p>changed for revision test</p>']);
    $rev = $repo->revisions($h1)[0];
    check('restoring a revision keeps the current address', $w->restoreRevision($h1, (int) $rev['id'], 1) === null && $P($h1) === 'other-book/part-one');
    $w->trash($h2);
    check('a trashed item keeps its address (it stays reserved)', $P($h2) === 'other-book/part-one/article-1' && $repo->pathTaken('other-book/part-one/article-1') && $repo->findByPath('other-book/part-one/article-1') === null);
    $w->purge($h2);
    check('purging frees the address and removes its redirects', !$repo->pathTaken('other-book/part-one/article-1') && (int) $db->value('SELECT COUNT(*) FROM {p}content_redirects WHERE content_id = ?', [$h2]) === 0);
    check('Unicode addresses are percent-encoded per segment in URLs', content_url(['path' => 'book/अनुच्छेद-21']) === '/book/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6-21' && content_url(['slug' => 'plain']) === '/plain');
    $hi = $mk(['title' => 'भारतीय संविधान', 'type' => 'book']);
    $hic = $mk(['title' => 'अनुच्छेद 21', 'type' => 'book', 'parent_id' => (string) $hi]);
    check('Hindi parent and child produce a Hindi address', $P($hic) === 'भारतीय-संविधान/अनुच्छेद-21' && $repo->findByPath('भारतीय-संविधान/अनुच्छेद-21')['id'] == $hic);

    $seg = static fn (string $ch): string => str_repeat($ch, 120);
    $lvl = [];
    $parent = '';
    foreach (['q', 'r', 's', 't'] as $ch) {
        $lvl[] = $mk(['title' => 'Deep ' . $ch, 'type' => 'book', 'slug' => $seg($ch), 'parent_id' => $parent]);
        $parent = (string) end($lvl);
    }
    check('an address of 4 long segments is accepted (483 characters)', strlen($P($lvl[3])) === 483);
    [$e] = $w->validate(['title' => 'Too deep', 'type' => 'book', 'slug' => $seg('u'), 'parent_id' => (string) $lvl[3]], null);
    check('an address over 500 characters is rejected', $e !== [] && str_contains(implode(' ', $e), 'too long'));
    $short = $mk(['title' => 'Short root', 'type' => 'book', 'slug' => 'sr']);
    $kid = $mk(['title' => 'Kid', 'type' => 'book', 'slug' => $seg('v'), 'parent_id' => (string) $short]);
    $kid2 = $mk(['title' => 'Kid2', 'type' => 'book', 'slug' => $seg('w'), 'parent_id' => (string) $kid]);
    $kid3 = $mk(['title' => 'Kid3', 'type' => 'book', 'slug' => $seg('x'), 'parent_id' => (string) $kid2]);
    $kid4 = $mk(['title' => 'Kid4', 'type' => 'book', 'slug' => $seg('z'), 'parent_id' => (string) $kid3]);
    check('renaming a parent so a descendant address would exceed 500 characters is rejected', $upd($short, ['slug' => $seg('y')]) !== [] && $P($short) === 'sr');

    echo "Migration 0009 on an existing (Phase 4A) database\n";
    $m9dir = sys_get_temp_dir() . '/cms_m9_' . bin2hex(random_bytes(4));
    mkdir($m9dir);
    foreach (glob(CMS_ROOT . '/core/migrations/000[1-8]_*') as $f) { copy($f, $m9dir . '/' . basename($f)); }
    $m9 = new Database($dbCfg + ['prefix' => 'm9_']);
    $dropAll = static function (Database $d): void { foreach (['content_assets', 'content_tags', 'content_redirects', 'content_revisions', 'content', 'tags', 'code_assets', 'media', 'search_index', 'login_attempts', 'recovery_codes', 'users', 'settings', 'schema_migrations'] as $t) { $d->exec('DROP TABLE IF EXISTS {p}' . $t); } };
    $dropAll($m9);
    (new Migrator($m9, $m9dir))->run();
    $ins = static function (Database $d, ?int $parent, string $slug, string $title, ?string $path = null): int {
        $cols = $path === null ? '' : ', path';
        $vals = $path === null ? '' : ', ?';
        $d->run("INSERT INTO {p}content (type, parent_id, position, slug, title, status, visibility, created_at, updated_at" . $cols . ") VALUES ('book', ?, 1, ?, ?, 'published', 'public', '2026-01-01 00:00:00', '2026-01-01 00:00:00'" . $vals . ")", array_merge([$parent, $slug, $title], $path === null ? [] : [$path]));
        return $d->insertId();
    };
    $r1 = $ins($m9, null, 'book', 'Book');
    $r2 = $ins($m9, null, 'other', 'Other');
    $c1 = $ins($m9, $r1, 'part', 'Part');
    $g1 = $ins($m9, $c1, 'art', 'Art');
    $m9->run('INSERT INTO {p}content_redirects (old_slug, content_id, created_at) VALUES (?, ?, ?)', ['earlier-name', $r1, '2026-01-01 00:00:00']);
    copy(CMS_ROOT . '/core/migrations/0009_content_paths.php', $m9dir . '/0009_content_paths.php');
    check('migration 0009 applies to existing data', (new Migrator($m9, $m9dir))->run() === ['0009_content_paths']);
    $pp = array_column($m9->all('SELECT id, path FROM {p}content'), 'path', 'id');
    check('existing items get addresses from their parents', $pp[$r1] === 'book' && $pp[$c1] === 'book/part' && $pp[$g1] === 'book/part/art' && $pp[$r2] === 'other');
    $rd = array_column($m9->all('SELECT old_path, content_id FROM {p}content_redirects'), 'content_id', 'old_path');
    check('old flat addresses of child pages redirect; the earlier 4A redirect survives the column rename; top-level items need none', (int) $rd['part'] === $c1 && (int) $rd['art'] === $g1 && (int) $rd['earlier-name'] === $r1 && !isset($rd['book']) && !isset($rd['other']));
    $c2 = $ins($m9, $r2, 'part', 'Part under other', 'other/part');
    check('the global slug uniqueness is gone: the same slug works under another parent', $m9->value('SELECT path FROM {p}content WHERE id = ?', [$c2]) === 'other/part');
    $dupe = false;
    try { $m9->run('INSERT INTO {p}content (type, parent_id, position, slug, path, title, status, visibility, created_at, updated_at) VALUES (\'book\', NULL, 1, \'x\', \'book\', \'x\', \'draft\', \'public\', NOW(), NOW())'); } catch (PDOException $ex) { $dupe = true; }
    check('addresses are unique at the database level', $dupe);
    $again = require CMS_ROOT . '/core/migrations/0009_content_paths.php';
    $again($m9);
    check('running the migration logic twice changes nothing', (int) $m9->value('SELECT COUNT(*) FROM {p}content_redirects') === 3);
    $dropAll($m9);
    exec('rm -rf ' . escapeshellarg($m9dir));

    echo "Tags\n";
    $trepo = new TagRepository($db);
    $tw = new TagWriter($db);
    $t1 = $mk(['title' => 'Tagged one', 'tags' => 'Law, Constitution notes, अनुच्छेद']);
    $t2 = $mk(['title' => 'Tagged two', 'tags' => 'law, Other']);
    $names1 = array_column($trepo->forContent($t1), 'name');
    sort($names1);
    $lawId = (int) $db->value("SELECT id FROM {p}tags WHERE name = 'Law'");
    check('tags are created on save and linked', $names1 === ['Constitution notes', 'Law', 'अनुच्छेद']);
    check('a tag typed in another case reuses the existing tag', (int) $db->value("SELECT COUNT(*) FROM {p}tags WHERE name = 'law'") === 1 && in_array($lawId, array_map('intval', array_column($trepo->forContent($t2), 'id')), true));
    check('tag slugs: ASCII and Hindi', $trepo->find($lawId)['slug'] === 'law' && $db->value("SELECT slug FROM {p}tags WHERE name = 'अनुच्छेद'") === 'अनुच्छेद');
    $upd($t2, ['tags' => 'Other']);
    check('removing a tag from an item unlinks it but keeps the tag', !in_array($lawId, array_map('intval', array_column($trepo->forContent($t2), 'id')), true) && $trepo->find($lawId) !== null);
    $tp = $mk(['title' => 'Private tagged', 'tags' => 'Law', 'visibility' => 'private', 'view_password' => 'a-long-password']);
    $td = $mk(['title' => 'Draft tagged', 'tags' => 'Law', 'status' => 'draft']);
    $tt = $mk(['title' => 'Trashed tagged', 'tags' => 'Law']);
    $w->trash($tt);
    $lawRow = array_values(array_filter($trepo->adminList(1, 100)['rows'], static fn ($r) => (int) $r['id'] === $lawId))[0];
    check('admin counts: all items vs items visible to the public', (int) $lawRow['items'] === 4 && (int) $lawRow['public_items'] === 1);
    $pub = $trepo->publicItems($lawId, 1, 10);
    check('public tag listing: only published, public, non-trashed items', $pub['total'] === 1 && $pub['rows'][0]['title'] === 'Tagged one');
    check('rename keeps the web address and refreshes the search index', $tw->rename($lawId, 'Statute law') === null && $trepo->find($lawId)['slug'] === 'law' && str_contains((string) $db->value('SELECT tags_text FROM {p}search_index WHERE content_id = ?', [$t1]), 'Statute law'));
    check('rename: duplicate name and commas refused', $tw->rename($lawId, 'Other') !== null && $tw->rename($lawId, 'a, b') !== null && $tw->rename($lawId, '') !== null);
    $tw->delete($lawId);
    check('deleting a tag unlinks it everywhere and refreshes the index; items stay', $trepo->find($lawId) === null && $repo->find($t1) !== null && !str_contains((string) $db->value('SELECT tags_text FROM {p}search_index WHERE content_id = ?', [$t1]), 'Statute'));
    [$e] = $w->validate(['title' => 'X', 'type' => 'page', 'tags' => implode(',', range(1, 16))], null);
    check('more than 15 tags rejected by the editor validation', $e !== []);
    [$e, $clean] = $w->validate(['title' => 'X', 'type' => 'page', 'tags' => 'a, b'], null);
    check('tags arrive as a clean list', $e === [] && $clean['tags'] === ['a', 'b']);

    echo "Search index and search\n";
    $idx = new SearchIndex($db);
    $sA = $mk(['title' => 'Constitutional remedies explained', 'body' => '<p>Article 32 gives the right to constitutional remedies</p>', 'tags' => 'writs']);
    $sB = $mk(['title' => 'Notes on property', 'body' => '<p>Article 300A property rights</p>']);
    $sC = $mk(['title' => 'अनुच्छेद 21 जीवन का अधिकार', 'body' => '<p>जीवन और व्यक्तिगत स्वतंत्रता का संरक्षण</p>']);
    $sD = $mk(['title' => 'Draft secret', 'body' => '<p>secretword</p>', 'status' => 'draft']);
    $sE = $mk(['title' => 'Private secret', 'body' => '<p>secretword</p>', 'visibility' => 'private', 'view_password' => 'another-long-one']);
    $sF = $mk(['title' => 'Trashed secret', 'body' => '<p>secretword</p>']);
    $w->trash($sF);
    $sG = $mk(['title' => 'Public secret', 'body' => '<p>secretword is here</p>']);
    $ids4 = static fn (array $r): array => array_map('intval', array_column($r['rows'], 'id'));
    $run = static function (string $mode) use ($idx, $db, $ids4, $sA, $sB, $sC, $sG): void {
        (new Settings($db))->set('search_mode', $mode);
        $tag = '[' . $mode . '] ';
        check($tag . 'finds by title word (prefix)', $ids4($idx->search('constitutional', 1, 10)) === [$sA]);
        check($tag . 'finds by body word; two words must both match', $ids4($idx->search('remedies explained', 1, 10)) === [$sA] && count($idx->search('article', 1, 10)['rows']) >= 2);
        check($tag . 'finds by tag', $ids4($idx->search('writs', 1, 10)) === [$sA]);
        check($tag . 'finds Hindi text', $ids4($idx->search('स्वतंत्रता', 1, 10)) === [$sC] && $ids4($idx->search('अधिकार', 1, 10)) === [$sC] && in_array($sC, $ids4($idx->search('अनुच्छेद', 1, 10)), true));
        check($tag . 'drafts, private and trashed items never appear', $ids4($idx->search('secretword', 1, 10)) === [$sG]);
        check($tag . 'case-insensitive', $ids4($idx->search('CONSTITUTIONAL REMEDIES', 1, 10)) === [$sA]);
        check($tag . 'no match, symbols only and empty queries', $idx->search('zzzzqqqq', 1, 10)['total'] === 0 && $idx->search('%%%', 1, 10)['total'] === 0 && $idx->search('', 1, 10)['total'] === 0 && $idx->search('_', 1, 10)['total'] === 0);
        $pg = $idx->search('article', 1, 1);
        check($tag . 'pagination: total counts all, page holds one', $pg['total'] >= 2 && count($pg['rows']) === 1 && $idx->search('article', 2, 1)['rows'][0]['id'] !== $pg['rows'][0]['id']);
        check($tag . 'stopwords and 2-letter words use the LIKE path and still work', $idx->search('the', 1, 10)['total'] >= 0 && $idx->search('ar', 1, 10)['total'] >= 2);
    };
    check('search mode is detected (fulltext on this database)', in_array($idx->mode(), ['fulltext', 'like'], true) && $idx->mode() === 'fulltext');
    $run('fulltext');
    $run('like');
    (new Settings($db))->set('search_mode', 'like');
    $rk1 = $mk(['title' => 'Ranking alpha', 'body' => '<p>zebra</p>']);
    $rk2 = $mk(['title' => 'Zebra in the title', 'body' => '<p>nothing</p>']);
    check('LIKE mode ranks title matches first', $ids4($idx->search('zebra', 1, 10)) === [$rk2, $rk1]);
    (new Settings($db))->set('search_mode', 'fulltext');
    $upd($sA, ['body' => '<p>Habeas corpus only</p>']);
    check('editing content updates the index (old words gone, new words found)', $idx->search('gives', 1, 10)['total'] === 0 && $ids4($idx->search('habeas', 1, 10)) === [$sA]);
    $w->restore($sF);
    check('restoring from the trash makes it searchable again', in_array($sF, $ids4($idx->search('secretword', 1, 10)), true));
    $w->trash($sF);
    $w->purge($sF);
    check('purging removes the index entry', (int) $db->value('SELECT COUNT(*) FROM {p}search_index WHERE content_id = ?', [$sF]) === 0);
    $expected = (int) $db->value('SELECT COUNT(*) FROM {p}content WHERE deleted_at IS NULL');
    $db->exec('DELETE FROM {p}search_index');
    $part = $idx->rebuild($store, 0, 5, microtime(true) - 1);
    check('rebuild stops at the deadline and reports where to resume', $part['next'] !== null && $part['done'] === 1);
    $rest = $idx->rebuild($store, $part['next'], 50, microtime(true) + 30);
    check('rebuild resumes and completes; every non-trashed item is indexed', $rest['next'] === null && (int) $db->value('SELECT COUNT(*) FROM {p}search_index') === $expected && $ids4($idx->search('habeas', 1, 10)) === [$sA]);
    $idx->upsert($sA, 'Constitutional remedies explained', '', '<p>Habeas corpus only</p>');
    check('upsert twice keeps one row', (int) $db->value('SELECT COUNT(*) FROM {p}search_index WHERE content_id = ?', [$sA]) === 1);

    echo "Content extras: redirects, SEO fields, settings validation\n";
    $rd = $mk(['title' => 'Redirect Me']);
    $upd($rd, ['slug' => 'moved-here']);
    check('renaming a slug records the old one as a redirect', (int) $db->value('SELECT content_id FROM {p}content_redirects WHERE old_path = ?', ['redirect-me']) === $rd);
    $upd($rd, ['slug' => 'redirect-me']);
    check('moving back removes that redirect (a live slug always wins) and records the other', (int) $db->value('SELECT COUNT(*) FROM {p}content_redirects WHERE old_path = ?', ['redirect-me']) === 0 && (int) $db->value('SELECT content_id FROM {p}content_redirects WHERE old_path = ?', ['moved-here']) === $rd);
    $png = $ids['tiny.png'];
    [$e] = $w->validate(['title' => 'S', 'type' => 'page', 'og_image_media_id' => (string) $ids['tiny.pdf']], null);
    check('a PDF cannot be the social image', $e !== []);
    [$e, $clean] = $w->validate(['title' => 'S', 'type' => 'page', 'og_image_media_id' => (string) $png, 'seo_title' => 'A title'], null);
    check('an image can be the social image; SEO title kept', $e === [] && $clean['og_image_media_id'] === $png && $clean['seo_title'] === 'A title');
    [$e] = $w->validate(['title' => 'S', 'type' => 'page', 'seo_title' => str_repeat('x', 161)], null);
    check('over-long SEO title rejected', $e !== []);
    $og = $mk(['title' => 'With social image', 'og_image_media_id' => (string) $png]);
    check('social image is stored and counted as media usage', (int) $repo->find($og)['og_image_media_id'] === $png && in_array('the social image of "With social image"', $mw->usage($png), true) && $mw->delete($png) !== null);
    [$e, $c] = SettingsAdmin::validate(['site_name' => 'My Site', 'site_url' => 'https://Example.com/', 'site_lang' => 'hi', 'site_description' => 'd', 'site_logo_media_id' => (string) $png, 'site_social_media_id' => ''], $mrepo);
    check('settings: valid input is normalised', $e === [] && $c['site_url'] === 'https://example.com' && $c['site_lang'] === 'hi' && $c['site_logo_media_id'] === (string) $png && $c['site_social_media_id'] === '');
    [$e] = SettingsAdmin::validate(['site_name' => '', 'site_url' => 'nope', 'site_lang' => 'English!', 'site_description' => str_repeat('x', 321), 'site_logo_media_id' => (string) $ids['tiny.pdf'], 'site_social_media_id' => '999999'], $mrepo);
    check('settings: bad name, URL, language, description and non-image logos are all reported', count($e) === 6);

    $base = ['site_name' => 'S', 'site_url' => '', 'site_lang' => 'en', 'site_description' => '', 'site_logo_media_id' => '', 'site_social_media_id' => ''];
    [$e, $c] = SettingsAdmin::validate($base, $mrepo);
    check('global settings: defaults when the new fields are absent', $e === [] && $c['items_per_page'] === '10' && $c['timezone'] === 'UTC' && $c['date_format'] === 'j M Y' && $c['footer_text'] === '' && $c['maintenance_mode'] === '0' && $c['maintenance_message'] === '');
    [$e, $c] = SettingsAdmin::validate($base + ['items_per_page' => '25', 'timezone' => 'Asia/Kolkata', 'date_format' => 'd/m/Y', 'footer_text' => "  (c) {year}\n\tMe  ", 'maintenance_mode' => '1', 'maintenance_message' => ' Back soon '], $mrepo);
    check('global settings: valid values are accepted and normalised (control characters in the footer become spaces)', $e === [] && $c['items_per_page'] === '25' && $c['timezone'] === 'Asia/Kolkata' && $c['date_format'] === 'd/m/Y' && $c['footer_text'] === '(c) {year} Me' && $c['maintenance_mode'] === '1' && $c['maintenance_message'] === 'Back soon');
    foreach (['4', '51', 'abc', '-5', '10.5', '0'] as $bad) {
        check('items per page "' . $bad . '" is rejected', SettingsAdmin::validate($base + ['items_per_page' => $bad], $mrepo)[0] !== []);
    }
    check('a rejected value is kept as typed so the form can show it again', SettingsAdmin::validate($base + ['items_per_page' => '3'], $mrepo)[1]['items_per_page'] === '3');
    check('boundary values 5 and 50 are accepted', SettingsAdmin::validate($base + ['items_per_page' => '5'], $mrepo)[0] === [] && SettingsAdmin::validate($base + ['items_per_page' => '50'], $mrepo)[0] === []);
    check('an unknown time zone or date format is rejected', SettingsAdmin::validate($base + ['timezone' => 'Mars/Base'], $mrepo)[0] !== [] && SettingsAdmin::validate($base + ['date_format' => 'Y"; drop'], $mrepo)[0] !== []);
    check('over-long footer text and maintenance message are rejected', SettingsAdmin::validate($base + ['footer_text' => str_repeat('x', 301)], $mrepo)[0] !== [] && SettingsAdmin::validate($base + ['maintenance_message' => str_repeat('x', 501)], $mrepo)[0] !== []);
    check('maintenance mode is on only for the exact value 1', SettingsAdmin::validate($base + ['maintenance_mode' => 'on'], $mrepo)[1]['maintenance_mode'] === '0' && SettingsAdmin::validate($base + ['maintenance_mode' => '1'], $mrepo)[1]['maintenance_mode'] === '1');
    (new Settings($db))->set('footer_text', 'Footer {year}');
    check('SiteInfo exposes the footer text', SiteInfo::load(new Settings($db), $mrepo)['footer'] === 'Footer {year}');
    (new Settings($db))->set('footer_text', '');
    exec('rm -rf ' . escapeshellarg($udir) . ' ' . escapeshellarg($tmp));


    echo "Autocomplete lookups\n";
    $lk = new LookupService($db);
    $lkLabels = static fn (array $items): array => array_column($items, 'label');
    $recent = $lk->contentParents('');
    check('parent picker with an empty query: recent books, at most 12, never pages or articles', count($recent) >= 1 && count($recent) <= 12 && array_intersect($lkLabels($recent), ['Tagged one', 'Tagged two', 'First Post']) === []);
    $allBookIds = array_map('intval', array_column($db->all("SELECT id FROM {p}content WHERE type = 'book' AND deleted_at IS NULL"), 'id'));
    check('parent picker results are all non-trashed books', array_diff(array_column($recent, 'id'), $allBookIds) === []);
    $lkBook = $mk(['title' => 'Zebra Handbook', 'type' => 'book']);
    $lkKid = $mk(['title' => 'Zebra Chapter', 'type' => 'book', 'parent_id' => (string) $lkBook]);
    $lkGrand = $mk(['title' => 'Zebra Section', 'type' => 'book', 'parent_id' => (string) $lkKid]);
    $hits = $lk->contentParents('zebra');
    check('search by title: prefix matches first, then alphabetical; hint is the address', $lkLabels($hits) === ['Zebra Chapter', 'Zebra Handbook', 'Zebra Section'] && $hits[1]['hint'] === '/zebra-handbook' && $hits[0]['hint'] === '/zebra-handbook/zebra-chapter');
    check('search by address fragment', in_array('Zebra Section', $lkLabels($lk->contentParents('zebra-chapter/zebra-sec')), true) && count($lk->contentParents('zebra-chapter/zebra-sec')) === 1);
    $ex = $lk->contentParents('zebra', $lkKid);
    check('an item being edited and everything below it are excluded', $lkLabels($ex) === ['Zebra Handbook']);
    check('excluding an unknown id changes nothing', count($lk->contentParents('zebra', 987654)) === 3);
    check('search ignores pages and articles and treats % and _ literally', $lk->contentParents('Tagged one') === [] && $lk->contentParents('%') === [] && $lk->contentParents('_') === []);
    $w->trash($lkGrand);
    check('trashed books are not offered', !in_array('Zebra Section', $lkLabels($lk->contentParents('zebra')), true));
    for ($i = 0; $i < 14; $i++) { $mk(['title' => 'Many ' . $i, 'type' => 'book']); }
    check('results are limited to 12', count($lk->contentParents('many')) === 12);
    check('query is capped (very long input is harmless)', is_array($lk->contentParents(str_repeat('x', 5000))));

    $imgs = $lk->images('');
    check('media picker: images only, newest first, with thumbnail URL and dimensions', $imgs !== [] && !in_array('tiny.pdf', $lkLabels($imgs), true) && $imgs[0]['id'] > $imgs[count($imgs) - 1]['id'] && str_starts_with($imgs[0]['thumb'], '/user/uploads/') && str_contains($imgs[0]['hint'], 'px'));
    $mw->updateAlt($ids['tiny.png'], 'A very special dot');
    check('media search matches file name and alt text', array_column($lk->images('special dot'), 'id') === [$ids['tiny.png']] && in_array($ids['tiny.png'], array_column($lk->images('tiny.png'), 'id'), true) && $lk->images('%') === []);
    check('alt text is part of the hint', str_contains($lk->images('special dot')[0]['hint'], 'A very special dot'));

    $html = AutocompleteField::render(['id' => 'parent_id', 'name' => 'parent_id', 'source' => 'content-parent', 'value' => 7, 'label' => '"><script>alert(1)</script>', 'exclude' => 12, 'placeholder' => 'Search']);
    check('field markup: plain numeric input as no-JS fallback, endpoint and exclude in data attributes', str_contains($html, '<input type="number"') && str_contains($html, 'name="parent_id"') && str_contains($html, 'value="7"') && str_contains($html, 'data-ac-url="/admin/lookup/content-parent"') && str_contains($html, 'data-ac-exclude="12"') && str_contains($html, 'data-ac-value'));
    check('field markup: hostile label is escaped', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));
    check('field markup: no exclude attribute unless given; empty value allowed', !str_contains(AutocompleteField::render(['id' => 'x', 'name' => 'x', 'source' => 'media-image']), 'data-ac-exclude') && str_contains(AutocompleteField::render(['id' => 'x', 'name' => 'x', 'source' => 'media-image']), 'value=""'));

    $call = static function (string $path, string $method, array $get) use ($db): array {
        $_GET = $get;
        $c = new LookupAdmin($db, ['id' => 1]);
        ob_start();
        $status = @$c->handle($path, $method);
        $out = (string) ob_get_clean();
        $_GET = [];
        return [$status, $out];
    };
    [$st, $out] = $call('/admin/lookup/content-parent', 'GET', ['q' => 'zebra', 'exclude' => (string) $lkKid]);
    $j = json_decode($out, true);
    check('JSON endpoint: items for a query, exclusion applied', $st === 0 && $lkLabels($j['items']) === ['Zebra Handbook']);
    $evil = $mk(['title' => '</script><img src=x onerror=alert(1)>', 'type' => 'book']);
    [, $out] = $call('/admin/lookup/content-parent', 'GET', ['q' => 'onerror']);
    check('JSON endpoint: hostile titles cannot break out (angle brackets and quotes are unicode-escaped)', !str_contains($out, '<') && !str_contains($out, '>') && json_decode($out, true)['items'][0]['label'] === '</script><img src=x onerror=alert(1)>');
    [$st, $out] = $call('/admin/lookup/content-parent', 'GET', ['q' => ['array'], 'exclude' => 'abc']);
    check('JSON endpoint: odd parameters are ignored, not fatal', $st === 0 && is_array(json_decode($out, true)['items']));
    check('JSON endpoint: wrong method 405, unknown source 404', $call('/admin/lookup/content-parent', 'POST', [])[0] === 405 && $call('/admin/lookup/nothing', 'GET', [])[0] === 404 && $call('/admin/lookup/../x', 'GET', [])[0] === 404);
    [, $out] = $call('/admin/lookup/media-image', 'GET', ['q' => 'tiny']);
    check('JSON endpoint: media results carry thumb URLs', json_decode($out, true)['items'] !== [] && isset(json_decode($out, true)['items'][0]['thumb']));


    echo "Robots, sitemap and feed\n";
    $siteD = ['name' => 'Syn Site', 'lang' => 'hi', 'url' => 'https://example.com/blog', 'description' => 'About "this" site & more', 'logo' => '', 'logo_alt' => '', 'social_image' => '', 'social_image_path' => ''];
    $syn = new Syndication($db, $siteD);
    $sA = $mk(['title' => 'Syn public article', 'tags' => 'law, Notes & more', 'excerpt' => 'Short <b>excerpt</b> & text']);
    $sP = $mk(['title' => 'Syn public page', 'type' => 'page']);
    $sB = $mk(['title' => 'Syn book', 'type' => 'book']);
    $sC = $mk(['title' => 'Syn chapter', 'type' => 'book', 'parent_id' => (string) $sB]);
    $sD = $mk(['title' => 'Syn draft article', 'status' => 'draft']);
    $sV = $mk(['title' => 'Syn private article', 'visibility' => 'private', 'view_password' => 'a-long-password']);
    $sN = $mk(['title' => 'Syn noindex article']);
    $db->run('UPDATE {p}content SET noindex = 1 WHERE id = ?', [$sN]);
    $sT = $mk(['title' => 'Syn trashed article']);
    $w->trash($sT);
    $sH = $mk(['title' => 'अनुच्छेद संपादित']);
    $sX = $mk(['title' => 'R&D <b>"quoted"</b> & more']);
    $sK = $mk(['title' => "Bad\x01Char\x0bTitle"]);
    $robots = $syn->robotsTxt();
    check('robots.txt: blocks admin and search, allows the rest, points to the sitemap', str_contains($robots, "User-agent: *\nDisallow: /admin/\nDisallow: /search\nAllow: /") && str_contains($robots, 'Sitemap: https://example.com/blog/sitemap.xml'));
    check('robots.txt without a site URL has no Sitemap line', !str_contains((new Syndication($db, ['url' => ''] + $siteD))->robotsTxt(), 'Sitemap:'));
    $xml = $syn->sitemapXml();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $okXml = $dom->loadXML($xml);
    check('sitemap is well-formed XML in the sitemap namespace', $okXml && libxml_get_errors() === [] && $dom->documentElement->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9');
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $locs = [];
    foreach ($xp->query('//s:url/s:loc') as $n) { $locs[] = $n->textContent; }
    check('sitemap lists the home page and public items with full hierarchical addresses', in_array('https://example.com/blog/', $locs, true) && in_array('https://example.com/blog/' . $repo->find($sA)['path'], $locs, true) && in_array('https://example.com/blog/' . $repo->find($sC)['path'], $locs, true) && str_contains($repo->find($sC)['path'], '/'));
    $drop = array_map(static fn (int $id): string => 'https://example.com/blog/' . $repo->find($id)['path'], [$sD, $sV, $sN, $sT]);
    check('drafts, private pages, noindex pages and trashed items are not in the sitemap', array_intersect($drop, $locs) === []);
    check('Hindi addresses are percent-encoded', in_array('https://example.com/blog/' . implode('/', array_map('rawurlencode', explode('/', $repo->find($sH)['path']))), $locs, true));
    check('no duplicate URLs', count($locs) === count(array_unique($locs)));
    $lastmods = [];
    foreach ($xp->query('//s:url/s:lastmod') as $n) { $lastmods[] = $n->textContent; }
    check('every URL has an ISO 8601 UTC lastmod', count($lastmods) === count($locs) && count(array_filter($lastmods, static fn ($l) => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $l) === 1)) === count($lastmods));
    check('no site URL: sitemap and feed are unavailable, not guessed', (new Syndication($db, ['url' => ''] + $siteD))->sitemapXml() === null && (new Syndication($db, ['url' => ''] + $siteD))->feedXml() === null);
    $e1 = $syn->sitemapStats()['etag'];
    check('sitemap ETag is stable while nothing changes', $syn->sitemapStats()['etag'] === $e1);
    $db->run("UPDATE {p}content SET updated_at = '2030-01-01 00:00:00' WHERE id = ?", [$sA]);
    check('sitemap ETag changes when content changes', $syn->sitemapStats()['etag'] !== $e1);
    $e2 = $syn->sitemapStats()['etag'];
    $db->run("UPDATE {p}content SET path = 'renamed-in-same-second' WHERE id = ?", [$sA]);
    check('a rename changes the sitemap ETag even when no timestamp moves', $syn->sitemapStats()['etag'] !== $e2);
    $db->run('UPDATE {p}content SET path = ? WHERE id = ?', [$repo->find($sA)['slug'], $sA]);

    $feed = $syn->feedXml();
    $fd = new DOMDocument();
    $okFeed = $fd->loadXML($feed);
    check('feed is well-formed RSS 2.0 with an Atom self link', $okFeed && libxml_get_errors() === [] && $fd->documentElement->nodeName === 'rss' && $fd->documentElement->getAttribute('version') === '2.0' && str_contains($feed, '<atom:link href="https://example.com/blog/feed.xml" rel="self" type="application/rss+xml"/>'));
    libxml_clear_errors();
    $fx = new DOMXPath($fd);
    $titles = [];
    foreach ($fx->query('//item/title') as $n) { $titles[] = $n->textContent; }
    check('feed has articles only (no pages or books), at most 20 items', count($titles) <= 20 && !in_array('Syn public page', $titles, true) && !in_array('Syn book', $titles, true) && in_array('Syn public article', $titles, true));
    check('feed leaves out drafts, private, noindex and trashed articles', array_intersect($titles, ['Syn draft article', 'Syn private article', 'Syn noindex article', 'Syn trashed article']) === []);
    check('special characters in titles survive the round trip through XML', in_array('R&D <b>"quoted"</b> & more', $titles, true) && !str_contains($feed, 'R&D <b>'));
    check('control characters XML forbids are removed', in_array('BadCharTitle', $titles, true) && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $feed));
    check('channel: title, link, description (entities escaped), language', $fx->query('/rss/channel/title')->item(0)->textContent === 'Syn Site' && $fx->query('/rss/channel/link')->item(0)->textContent === 'https://example.com/blog/' && $fx->query('/rss/channel/description')->item(0)->textContent === 'About "this" site & more' && $fx->query('/rss/channel/language')->item(0)->textContent === 'hi');
    $item = $fx->query('//item[title="Syn public article"]')->item(0);
    check('item: permalink guid and link, RFC 822 date, plain-text description, tags as categories', $item->getElementsByTagName('guid')->item(0)->getAttribute('isPermaLink') === 'true' && $item->getElementsByTagName('link')->item(0)->textContent === 'https://example.com/blog/' . $repo->find($sA)['path'] && preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} \+0000$/', $item->getElementsByTagName('pubDate')->item(0)->textContent) === 1 && $item->getElementsByTagName('description')->item(0)->textContent === 'Short excerpt & text' && array_map(static fn ($n) => $n->textContent, iterator_to_array($item->getElementsByTagName('category'))) === ['law', 'Notes & more']);
    check('feed items are newest first', (function () use ($fx) { $d = []; foreach ($fx->query('//item/pubDate') as $n) { $d[] = strtotime($n->textContent); } $s2 = $d; rsort($s2); return $d === $s2; })());
    for ($i = 0; $i < 25; $i++) { $mk(['title' => 'Feed filler ' . $i]); }
    check('the feed is limited to 20 items', substr_count($syn->feedXml(), '<item>') === 20);
    check('xml(): escapes and drops invalid characters', Syndication::xml("a&b<c>\"d'\x00\x1f e") === 'a&amp;b&lt;c&gt;&quot;d&apos; e');

    echo "Theme rendering (runtime fallback)\n";
    $logFile = CMS_ROOT . '/user/logs/app.log';
    $logExisted = is_file($logFile);
    $rcore = sys_get_temp_dir() . '/cms_rcore_' . bin2hex(random_bytes(4));
    $ruser = sys_get_temp_dir() . '/cms_ruser_' . bin2hex(random_bytes(4));
    mkdir($rcore . '/themes', 0755, true);
    exec('cp -r ' . escapeshellarg(CMS_ROOT . '/core/themes/base') . ' ' . escapeshellarg($rcore . '/themes/base'));
    $put = static function (string $path, string $data) { if (!is_dir(dirname($path))) { mkdir(dirname($path), 0755, true); } file_put_contents($path, $data); };
    $put($ruser . '/themes/ok/theme.json', json_encode(['name' => 'Ok', 'version' => '1.0.0']));
    $put($ruser . '/themes/ok/partials/footer.php', '<footer id="kidfooter">KID FOOTER</footer>');
    $put($ruser . '/themes/ok/templates/page-about.php', '<h1>ABOUT TEMPLATE <?= e($item["title"]) ?></h1>');
    $put($ruser . '/themes/boom/theme.json', json_encode(['name' => 'Boom', 'version' => '1.0.0']));
    $put($ruser . '/themes/boom/templates/article.php', '<?php throw new RuntimeException("template exploded"); ?>');
    $put($ruser . '/themes/parse/theme.json', json_encode(['name' => 'Parse', 'version' => '1.0.0']));
    $put($ruser . '/themes/parse/layout.php', '<?php if ( { ?>');
    $settings = new Settings($db);
    $item = ['id' => 1, 'type' => 'article', 'slug' => 'x', 'title' => 'Hello Title', 'published_at' => '2026-01-02 03:04:05', 'excerpt' => '', 'visibility' => 'public', 'noindex' => 0];
    $vars = ['title' => 'Hello Title', 'item' => $item, 'body' => '<p>BODY TEXT</p>'];

    $settings->set('active_theme', 'base');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article-x', 'article', 'content'], $vars);
    check('base theme renders a full page', str_contains($html, '<!doctype html>') && str_contains($html, 'Hello Title') && str_contains($html, 'BODY TEXT') && str_contains($html, '<body class="template-article">'));
    check('layout links base css/js through /assets/base and carries Bootstrap with SRI', str_contains($html, '/assets/base/css/theme.css?v=') && str_contains($html, '/assets/base/js/theme.js?v=') && str_contains($html, 'integrity="sha384-'));
    check('head and footer assets are in the right places', strpos($html, 'theme.css') < strpos($html, '</head>') && strpos($html, 'theme.js') > strpos($html, '</main>'));
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], $vars + ['itemAssets' => ['css' => ['/user/assets/css/1-x.css?v=abc'], 'js' => ['/user/assets/js/2-y.js?v=def']]]);
    check('linked stylesheets load in the head, linked scripts deferred at the end', strpos($html, '<link rel="stylesheet" href="/user/assets/css/1-x.css?v=abc">') < strpos($html, '</head>') && strpos($html, '<script src="/user/assets/js/2-y.js?v=def" defer></script>') > strpos($html, '</main>'));
    check('no linked assets means no extra tags', !str_contains((new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], $vars), '/user/assets/'));
    check('without a logo the header shows only the site name', !str_contains($html, '<img'));
    $settings->set('site_logo_media_id', (string) $ids['tiny.png']);
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], $vars);
    check('a logo set in Site settings appears in the header', str_contains($html, '<img src="/user/uploads/') && str_contains($html, 'height="32"'));
    $settings->set('site_logo_media_id', '');
    $seoV = ['title' => 'Custom "SEO" <title>', 'description' => 'Desc "with" <quotes>', 'canonical' => 'https://example.com/a?b=1&c=2', 'robots' => 'noindex, follow',
        'og' => ['og:title' => 'T"><script>alert(1)</script>', 'og:type' => 'article'], 'twitter' => ['twitter:card' => 'summary'], 'feed' => 'https://example.com/feed.xml'];
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], ['seo' => $seoV, 'tags' => [['name' => 'Law & order', 'slug' => 'law-order'], ['name' => 'अनुच्छेद', 'slug' => 'अनुच्छेद']]] + $vars);
    check('meta partial prints description, robots, canonical, og, twitter and the feed link', str_contains($html, '<meta name="description" content="Desc &quot;with&quot; &lt;quotes&gt;">') && str_contains($html, '<meta name="robots" content="noindex, follow">') && str_contains($html, '<link rel="canonical" href="https://example.com/a?b=1&amp;c=2">') && str_contains($html, '<meta property="og:type" content="article">') && str_contains($html, '<meta name="twitter:card" content="summary">') && str_contains($html, 'type="application/rss+xml"') && str_contains($html, 'href="https://example.com/feed.xml"'));
    check('hostile values cannot break out of attributes or the page', !str_contains($html, '<script>alert(1)') && str_contains($html, 'T&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;'));
    check('the SEO title replaces the page title in <title>', str_contains($html, '<title>Custom &quot;SEO&quot; &lt;title&gt; | ') && !str_contains($html, '<title>Hello Title'));
    check('tag links are shown on the page with encoded addresses', str_contains($html, 'href="/tag/law-order" rel="tag">Law &amp; order</a>') && str_contains($html, 'href="/tag/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6"'));
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], $vars + ['metaDescription' => 'Old style description', 'noindex' => true]);
    check('older variables still work when $seo is empty (compatibility)', str_contains($html, 'content="Old style description"') && str_contains($html, '<meta name="robots" content="noindex, nofollow">') && !str_contains($html, 'rel="canonical"') && str_contains($html, '<title>Hello Title | '));
    check('the header has the search form', str_contains($html, '<form class="d-flex" role="search" action="/search" method="get">') && str_contains($html, 'name="q"'));
    $pg = static fn (string $base, int $page, int $pages, array $query = []): string => (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['home'], ['articles' => [], 'page' => $page, 'pages' => $pages] + $vars);
    $searchHtml = static fn (array $over) => (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['search'], $over + ['q' => 'law', 'searched' => true, 'results' => [], 'total' => 0, 'page' => 1, 'pages' => 1] + $vars);
    $rows = [['type' => 'book', 'path' => 'book/part-one', 'slug' => 'part-one', 'title' => 'Part <One>', 'excerpt' => 'ex', 'published_at' => '2026-10-01 00:00:00']];
    $h = $searchHtml(['results' => $rows, 'total' => 25, 'pages' => 3, 'page' => 2]);
    check('search page: result links use the full address; the pager keeps the query', str_contains($h, 'href="/book/part-one">Part &lt;One&gt;</a>') && str_contains($h, 'Book Article') && str_contains($h, 'href="/search?q=law"') && str_contains($h, 'href="/search?q=law&amp;page=3"') && str_contains($h, '25 results'));
    check('search page: first-page "Newer" link has no page parameter, query is escaped in the text', str_contains($searchHtml(['q' => '<script>x</script>', 'total' => 0]), 'No results for &ldquo;&lt;script&gt;x&lt;/script&gt;&rdquo;') && !str_contains($searchHtml(['q' => '<script>x</script>']), '<script>x</script>'));
    check('search page: too-short query shows the hint and no results', str_contains($searchHtml(['searched' => false, 'q' => 'a']), 'Type at least 2 characters'));
    $th = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['tag'], ['tag' => ['id' => 1, 'name' => 'Law <x>', 'slug' => 'law'], 'items' => $rows, 'total' => 15, 'page' => 2, 'pages' => 2] + $vars);
    check('tag page: heading escaped, item links, pager under the tag address', str_contains($th, 'Tag: Law &lt;x&gt;') && str_contains($th, 'href="/book/part-one"') && str_contains($th, 'href="/tag/law"') && !str_contains($th, 'href="/tag/law?page=1"'));
    $home = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['home'], ['articles' => [], 'page' => 2, 'pages' => 3] + $vars);
    check('home pager still produces ?page= addresses and / for page 1', str_contains($home, 'href="/" rel="prev"') && str_contains($home, 'href="/?page=3" rel="next"'));

    $settings->set('footer_text', '(c) {year} <b>Me</b> & co');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], $vars);
    check('footer text from settings: {year} replaced, HTML escaped, replaces the default line', str_contains($html, '>(c) ' . gmdate('Y') . ' &lt;b&gt;Me&lt;/b&gt; &amp; co</footer>') && !str_contains($html, '&copy;'));
    $settings->set('footer_text', '');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], $vars);
    check('without footer text the default copyright line with the site name is shown', str_contains($html, '© ' . gmdate('Y') . ' '));
    Format::configure('Asia/Kolkata', 'j M Y');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article'], ['item' => ['published_at' => '2026-10-01 20:00:00'] + $item] + $vars);
    Format::configure('UTC', 'j M Y');
    check('theme dates follow the site zone: shown date and machine date agree', str_contains($html, '<time datetime="2026-10-02">2 Oct 2026</time>'));

    $settings->set('active_theme', 'ok');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article-x', 'article', 'content'], $vars);
    check('child partial overrides the base partial, other partials stay', str_contains($html, 'KID FOOTER') && !str_contains($html, '&copy;') && str_contains($html, 'navbar-brand'));
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['page-about', 'page', 'content'], ['item' => ['title' => 'About'] + $item] + $vars);
    check('most specific template candidate wins', str_contains($html, 'ABOUT TEMPLATE About') && str_contains($html, 'template-page-about'));
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['page-zzz', 'page', 'content'], $vars);
    check('falls through candidates to the base template', str_contains($html, 'Hello Title') && str_contains($html, 'cms-content'));

    $settings->set('active_theme', 'boom');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article', 'content'], $vars);
    check('a child template that throws falls back to the base theme', str_contains($html, 'BODY TEXT') && !str_contains($html, 'exploded') && str_contains($html, '<!doctype html>'));
    $settings->set('active_theme', 'parse');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article', 'content'], $vars);
    check('a child layout with a parse error falls back to the base theme', str_contains($html, 'BODY TEXT') && str_contains($html, 'navbar-brand'));
    $settings->set('active_theme', '../../etc');
    check('a malformed active_theme value uses the base theme', (new PublicRenderer($settings, $db, $ruser, $rcore))->theme()->name() === 'base');
    $settings->set('active_theme', 'deleted-theme');
    check('an active theme whose folder is gone uses the base theme', (new PublicRenderer($settings, $db, $ruser, $rcore))->theme()->name() === 'base');

    $settings->set('active_theme', 'base');
    unlink($rcore . '/themes/base/layout.php');
    $html = (new PublicRenderer($settings, $db, $ruser, $rcore))->render(['article', 'content'], $vars);
    check('if even the base layout is gone, the built-in safe page is used', str_contains($html, 'Hello Title') && str_contains($html, 'BODY TEXT') && !str_contains($html, 'navbar'));
    exec('rm -rf ' . escapeshellarg($rcore) . ' ' . escapeshellarg($ruser));
    if (!$logExisted && is_file($logFile)) { unlink($logFile); }
    foreach (['content_assets', 'content_tags', 'content_redirects', 'content_revisions', 'content', 'tags', 'code_assets', 'media', 'search_index', 'login_attempts', 'recovery_codes', 'users', 'settings', 'schema_migrations'] as $t) {
        $db->exec('DROP TABLE IF EXISTS {p}' . $t);
    }
} else {
    echo "Database tests skipped (set CMS_TEST_DB)\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
