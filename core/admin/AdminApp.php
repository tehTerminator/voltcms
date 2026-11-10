<?php
declare(strict_types=1);

namespace Cms;

/** Admin area controller (Phase 1: sign-in, two-factor, dashboard, security settings). */
final class AdminApp
{
    private const ROUTES = [
        '/admin' => ['GET'],
        '/admin/login' => ['GET', 'POST'],
        '/admin/login/2fa' => ['GET', 'POST'],
        '/admin/logout' => ['POST'],
        '/admin/security' => ['GET'],
        '/admin/security/password' => ['POST'],
        '/admin/security/2fa/start' => ['POST'],
        '/admin/security/2fa/setup' => ['GET'],
        '/admin/security/2fa/confirm' => ['POST'],
        '/admin/security/2fa/disable' => ['POST'],
        '/admin/update' => ['GET'],
        '/admin/update/apply' => ['POST'],
    ];
    private const PUBLIC_ROUTES = ['/admin/login', '/admin/login/2fa'];

    /** Route prefix => controller class (constructor: Database $db, array $user; handle(path, method): int status). */
    private const CONTROLLERS = [
        '/admin/content' => ContentAdmin::class,
        '/admin/themes' => ThemeAdmin::class,
        '/admin/media' => MediaAdmin::class,
        '/admin/code' => CodeAdmin::class,
        '/admin/tags' => TagAdmin::class,
        '/admin/settings' => SettingsAdmin::class,
        '/admin/lookup' => LookupAdmin::class,
    ];

    private Database $db;
    private Auth $auth;
    private ?array $user = null;

    public function __construct()
    {
        $this->db = App::db();
        $this->auth = new Auth($this->db, new RateLimiter($this->db));
    }

    public function handle(string $path, string $method): void
    {
        if (str_starts_with($path, '/admin/assets/')) {
            $this->serveAsset(substr($path, strlen('/admin/assets/')));
            return;
        }
        Session::start();
        Response::secureHeaders();
        Response::noCache();
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $controllerClass = null;
        foreach (self::CONTROLLERS as $prefix => $class) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $controllerClass = $class;
                break;
            }
        }
        if ($controllerClass === null && !isset(self::ROUTES[$path])) {
            $this->error(404, 'Page not found.');
            return;
        }
        if ($controllerClass === null && !in_array($method, self::ROUTES[$path], true)) {
            header('Allow: ' . implode(', ', self::ROUTES[$path]));
            $this->error(405, 'Method not allowed.');
            return;
        }
        if ($method === 'POST' && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            // PHP discards the whole request body when it exceeds post_max_size, so the CSRF token is gone too.
            $this->error(413, 'The upload is larger than this server allows (post_max_size is ' . ini_get('post_max_size') . '). Try a smaller file.');
            return;
        }
        if ($method === 'POST' && !Csrf::verify()) {
            $this->error(403, 'The form expired or was invalid. Go back, reload the page and try again.');
            return;
        }
        if (!in_array($path, self::PUBLIC_ROUTES, true)) {
            $this->user = $this->auth->user();
            if ($this->user === null) {
                Response::redirect('/admin/login');
                return;
            }
            $updatePending = Config::get('installed_version') !== CMS_VERSION;
            if ($updatePending && !in_array($path, ['/admin/update', '/admin/update/apply', '/admin/logout'], true)) {
                Response::redirect('/admin/update');
                return;
            }
            if (!$updatePending && in_array($path, ['/admin/update', '/admin/update/apply'], true)) {
                Response::redirect('/admin');
                return;
            }
        }
        if ($controllerClass !== null) {
            $status = (new $controllerClass($this->db, $this->user))->handle($path, $method);
            if ($status === 405) {
                $this->error(405, 'Method not allowed.');
            } elseif ($status !== 0) {
                $this->error(404, 'Page not found.');
            }
            return;
        }

        match ($method . ' ' . $path) {
            'GET /admin' => $this->dashboard(),
            'GET /admin/login' => $this->loginForm(),
            'POST /admin/login' => $this->loginSubmit(),
            'GET /admin/login/2fa' => $this->secondFactorForm(),
            'POST /admin/login/2fa' => $this->secondFactorSubmit(),
            'POST /admin/logout' => $this->logout(),
            'GET /admin/security' => $this->security(),
            'POST /admin/security/password' => $this->changePassword(),
            'POST /admin/security/2fa/start' => $this->twoFactorStart(),
            'GET /admin/security/2fa/setup' => $this->twoFactorSetup(),
            'POST /admin/security/2fa/confirm' => $this->twoFactorConfirm(),
            'POST /admin/security/2fa/disable' => $this->twoFactorDisable(),
            'GET /admin/update' => $this->updateForm(),
            'POST /admin/update/apply' => $this->updateApply(),
        };
    }

    // ---- sign-in -------------------------------------------------------------------------

    private function loginForm(): void
    {
        if ($this->auth->user() !== null) {
            Response::redirect('/admin');
            return;
        }
        $this->page('login', ['title' => 'Sign in', 'username' => '']);
    }

    private function loginSubmit(): void
    {
        $username = trim(Request::post('username'));
        switch ($this->auth->attempt($username, Request::post('password'))) {
            case 'ok':
                Response::redirect('/admin');
                return;
            case '2fa':
                Response::redirect('/admin/login/2fa');
                return;
            case 'blocked':
                Session::flash('danger', 'Too many failed attempts. Please wait 15 minutes and try again.');
                $this->page('login', ['title' => 'Sign in', 'username' => $username], 429);
                return;
            default:
                Session::flash('danger', 'Invalid username or password.');
                $this->page('login', ['title' => 'Sign in', 'username' => $username], 200);
        }
    }

    private function secondFactorForm(): void
    {
        if (!$this->auth->hasPendingSecondFactor()) {
            Response::redirect('/admin/login');
            return;
        }
        $this->page('login_2fa', ['title' => 'Two-factor verification']);
    }

    private function secondFactorSubmit(): void
    {
        switch ($this->auth->verifySecondFactor(Request::post('code'))) {
            case 'ok':
                Response::redirect('/admin');
                return;
            case 'expired':
                Session::flash('warning', 'Your sign-in expired. Please start again.');
                Response::redirect('/admin/login');
                return;
            case 'blocked':
                Session::flash('danger', 'Too many failed attempts. Please wait 15 minutes and try again.');
                $this->page('login_2fa', ['title' => 'Two-factor verification'], 429);
                return;
            default:
                Session::flash('danger', 'That code was not accepted.');
                $this->page('login_2fa', ['title' => 'Two-factor verification']);
        }
    }

    private function logout(): void
    {
        $this->auth->logout();
        Session::flash('success', 'You have been signed out.');
        Response::redirect('/admin/login');
    }

    // ---- dashboard -----------------------------------------------------------------------

    private function dashboard(): void
    {
        $this->page('dashboard', [
            'title' => 'Dashboard',
            'nav' => true,
            'user' => $this->user,
            'counts' => (new ContentRepository($this->db))->counts(),
            'themeName' => (string) (new Settings($this->db))->get('active_theme', 'base'),
            'siteUrlMissing' => (new Settings($this->db))->get('site_url', '') === '',
            'maintenanceOn' => (new Settings($this->db))->get('maintenance_mode', '0') === '1',
            'siteName' => (new Settings($this->db))->get('site_name', 'My site'),
            'dbVersion' => (string) $this->db->value('SELECT VERSION()'),
            'installerPresent' => is_file(CMS_ROOT . '/install.php'),
        ]);
    }

    // ---- update step (database migrations after uploading new program files) --------------------

    private function updateForm(): void
    {
        $migrator = new Migrator($this->db, CMS_ROOT . '/core/migrations');
        $this->page('update', [
            'title' => 'Update required', 'nav' => true,
            'pending' => $migrator->pending(), 'from' => (string) Config::get('installed_version', 'unknown'),
        ]);
    }

    private function updateApply(): void
    {
        try {
            $applied = (new Migrator($this->db, CMS_ROOT . '/core/migrations'))->run();
            Config::saveApp(['installed_version' => CMS_VERSION]);
            Logger::info('Update applied', ['version' => CMS_VERSION, 'migrations' => $applied]);
            Session::flash('success', 'Updated to version ' . CMS_VERSION . '.');
            Response::redirect('/admin');
        } catch (\Throwable $e) {
            Logger::error('Update failed: ' . $e->getMessage());
            Session::flash('danger', 'The update did not finish: ' . $e->getMessage() . ' Nothing was marked as updated, so you can fix the problem and try again.');
            Response::redirect('/admin/update');
        }
    }

    /** Static admin assets (script, stylesheet). Fixed whitelist, so no path can reach any other file. */
    private function serveAsset(string $name): void
    {
        $types = [
            'admin.js' => 'application/javascript; charset=utf-8',
            'autocomplete.js' => 'application/javascript; charset=utf-8',
            'admin.css' => 'text/css; charset=utf-8',
        ];
        $file = CMS_ROOT . '/core/admin/assets/' . $name;
        if (!isset($types[$name]) || !is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        header('Content-Type: ' . $types[$name]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
    }

    // ---- security settings ---------------------------------------------------------------

    private function security(): void
    {
        $this->page('security', ['title' => 'Security', 'nav' => true, 'user' => $this->user]);
    }

    private function changePassword(): void
    {
        $current = Request::post('current_password');
        $new = Request::post('new_password');
        $confirm = Request::post('confirm_password');
        $error = $this->passwordProblem($new, $confirm);
        if ($error === null && !$this->auth->confirmPassword($this->user, $current)) {
            $error = 'Your current password is incorrect, or there were too many attempts. Wait a few minutes and try again.';
        }
        if ($error === null && hash_equals($current, $new)) {
            $error = 'The new password must be different from the current one.';
        }
        if ($error !== null) {
            Session::flash('danger', $error);
        } else {
            $this->auth->changePassword((int) $this->user['id'], $new);
            Session::flash('success', 'Password changed.');
        }
        Response::redirect('/admin/security');
    }

    private function twoFactorStart(): void
    {
        if ((int) $this->user['totp_enabled'] === 1) {
            Response::redirect('/admin/security');
            return;
        }
        $_SESSION['_totp_setup'] = Crypto::encrypt(Totp::generateSecret());
        Response::redirect('/admin/security/2fa/setup');
    }

    private function twoFactorSetup(): void
    {
        $secret = $this->pendingSecret();
        if ($secret === null) {
            Response::redirect('/admin/security');
            return;
        }
        $issuer = (new Settings($this->db))->get('site_name', 'CMS') ?? 'CMS';
        $this->page('twofa_setup', [
            'title' => 'Set up two-factor authentication',
            'nav' => true,
            'secretGroups' => implode(' ', str_split($secret, 4)),
            'uri' => Totp::uri($secret, (string) $this->user['username'], $issuer),
        ]);
    }

    private function twoFactorConfirm(): void
    {
        $secret = $this->pendingSecret();
        if ($secret === null) {
            Response::redirect('/admin/security');
            return;
        }
        $code = (string) preg_replace('/\D/', '', Request::post('code'));
        $step = Totp::verify($secret, $code, null);
        if ($step === null) {
            Session::flash('danger', 'That code did not match. Check the time on your phone and try again.');
            Response::redirect('/admin/security/2fa/setup');
            return;
        }
        $this->db->run(
            'UPDATE {p}users SET totp_secret_enc = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?',
            [Crypto::encrypt($secret), $step, $this->user['id']]
        );
        unset($_SESSION['_totp_setup']);
        $codes = $this->auth->newRecoveryCodes((int) $this->user['id']);
        Logger::security('2fa_enabled', ['ip' => Request::ip()]);
        $this->page('recovery_codes', ['title' => 'Recovery codes', 'nav' => true, 'codes' => $codes]);
    }

    private function twoFactorDisable(): void
    {
        if (!$this->auth->confirmPassword($this->user, Request::post('current_password'))) {
            Session::flash('danger', 'Your password is incorrect, or there were too many attempts. Wait a few minutes and try again.');
            Response::redirect('/admin/security');
            return;
        }
        $this->db->transaction(function (Database $db): void {
            $db->run('UPDATE {p}users SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = ?', [$this->user['id']]);
            $db->run('DELETE FROM {p}recovery_codes WHERE user_id = ?', [$this->user['id']]);
        });
        Logger::security('2fa_disabled', ['ip' => Request::ip()]);
        Session::flash('success', 'Two-factor authentication is now off.');
        Response::redirect('/admin/security');
    }

    // ---- helpers -------------------------------------------------------------------------

    private function pendingSecret(): ?string
    {
        $enc = $_SESSION['_totp_setup'] ?? null;
        return is_string($enc) ? Crypto::decrypt($enc) : null;
    }

    private function passwordProblem(string $new, string $confirm): ?string
    {
        if (strlen($new) < 12) {
            return 'The new password must be at least 12 characters long.';
        }
        if (strlen($new) > 72) {
            return 'The new password must be at most 72 bytes long (a limit of the bcrypt hashing algorithm).';
        }
        return hash_equals($new, $confirm) ? null : 'The new password and its confirmation do not match.';
    }

    private function page(string $view, array $vars, int $status = 200): void
    {
        Response::html(View::render($view, $vars), $status);
    }

    private function error(int $code, string $message): void
    {
        $this->page('error', ['title' => 'Error ' . $code, 'code' => $code, 'message' => $message], $code);
    }
}
