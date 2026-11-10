"""HTTP end-to-end tests (Phase 1). Needs: python3 + requests, MySQL/MariaDB, a throwaway database.

1. Copy the project (without tests/) to a scratch folder and set ROOT to it.
2. Create an empty database and user, and put them in DB below and in the `good` install form further down.
3. In ROOT run:  php -S 127.0.0.1:8099 router.php
4. Run:          python3 tests/e2e_http.py
The suite installs the CMS, so it consumes the scratch copy (install.php deletes itself).
Constants below are the values used in the development sandbox: edit them for your machine.
"""
import re, sys, time, hmac, hashlib, struct, base64, os, stat
import requests

BASE = "http://127.0.0.1:8099"
ROOT = "/tmp/e2e"
res = {"pass": 0, "fail": 0}

def check(name, ok, extra=""):
    res["pass" if ok else "fail"] += 1
    print(("  ok   " if ok else "  FAIL ") + name + ((" -> " + str(extra)) if (not ok and extra) else ""))

def totp(secret_b32, step):
    key = base64.b32decode(secret_b32.replace(" ", "").upper() + "=" * (-len(secret_b32.replace(" ", "")) % 8))
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    o = h[19] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o+4])[0] & 0x7fffffff) % 1000000)

def csrf(html):
    m = re.search(r'name="_csrf" value="([0-9a-f]{64})"', html)
    return m.group(1) if m else ""

def new():
    s = requests.Session(); s.allow_redirects = False
    return s

def get(s, p, **k): return s.get(BASE + p, allow_redirects=False, **k)
def post(s, p, data, token_from=None, **k):
    if token_from is not None:
        data = dict(data, _csrf=csrf(get(s, token_from).text))
    return s.post(BASE + p, data=data, allow_redirects=False, **k)

s = new()
print("Installer")
r = get(s, "/")
check("home before install redirects to installer", r.status_code == 303 and r.headers["Location"].endswith("/install.php"))
r = get(s, "/install.php")
check("installer page renders", r.status_code == 200 and "Install the CMS" in r.text)
check("all required server checks OK", "badge text-bg-danger" not in r.text, re.findall(r'<td>([^<]*)</td>\s*<td class="text-end">\s*<span class="badge text-bg-danger', r.text))
check("CSP header present", "default-src 'none'" in r.headers.get("Content-Security-Policy", ""))
good = dict(db_host="127.0.0.1", db_port="3306", db_name="cmse2e", db_user="cms", db_pass="cmspass", prefix="cms_",
            site_name="Test Site", admin_username="prateek", admin_email="a@example.com",
            admin_password="correct horse battery", admin_password2="correct horse battery")
r = post(s, "/install.php", dict(good, admin_password="short", admin_password2="short"), token_from="/install.php")
check("short password rejected", "12 to 72 bytes" in r.text and "Installation complete" not in r.text)
r = post(s, "/install.php", dict(good, db_pass="wrong"), token_from="/install.php")
check("bad DB credentials reported, not installed", "Installation failed" in r.text and not os.path.exists(ROOT + "/user/config/installed.lock"))
r = post(s, "/install.php", good)
check("POST without CSRF token rejected", "form expired" in r.text and not os.path.exists(ROOT + "/user/config/installed.lock"))
r = post(s, "/install.php", good, token_from="/install.php")
check("install succeeds", "Installation complete" in r.text, r.text[:300])
check("lock file created", os.path.exists(ROOT + "/user/config/installed.lock"))
check("install.php removed itself", not os.path.exists(ROOT + "/install.php"))
mode = stat.S_IMODE(os.stat(ROOT + "/user/db.php").st_mode)
check("db.php mode 0640", mode == 0o640, oct(mode))
check("app.php has 32-byte key", len(base64.b64decode(re.search(r"'app_key' => '([^']+)'", open(ROOT + "/user/config/app.php").read()).group(1))) == 32)
r = get(s, "/install.php")
check("installer gone after install (404)", r.status_code == 404)

print("Access protection")
for p in ["/user/db.php", "/user/config/app.php", "/core/engine/Config.php", "/user/logs/app.log", "/.htaccess", "/core/VERSION", "/user/cache/sessions/x"]:
    check("blocked " + p, get(s, p).status_code in (403, 404) and get(s, p).status_code != 200)
check("traversal refused (403/404)", get(s, "/a/%2e%2e/%2e%2e/etc/passwd").status_code in (403, 404))
check("unknown path 404", get(s, "/nope").status_code == 404)

print("Admin gate and CSRF")
r = get(s, "/admin")
check("/admin redirects to login when signed out", r.status_code == 303 and r.headers["Location"].endswith("/admin/login"))
r = post(s, "/admin/login", dict(username="prateek", password="correct horse battery"))
check("login POST without CSRF -> 403", r.status_code == 403)
r = get(s, "/admin/logout")
check("GET /admin/logout -> 405", r.status_code == 405 and "POST" in r.headers.get("Allow", ""))
fresh = new()
r = get(fresh, "/admin/login")
for h in ["X-Frame-Options", "X-Content-Type-Options", "Referrer-Policy", "Content-Security-Policy", "Cache-Control"]:
    check("header " + h, h in r.headers)
sc = r.headers.get("Set-Cookie", "")  # fresh client, so the cookie is issued
check("session cookie HttpOnly + SameSite=Lax", "HttpOnly" in sc and "SameSite=Lax" in sc, sc)

print("Sign in")
pre_sid = s.cookies.get("cmssid")
r = post(s, "/admin/login", dict(username="prateek", password="nope nope nope nope"), token_from="/admin/login")
check("wrong password -> generic error", r.status_code == 200 and "Invalid username or password." in r.text)
r = post(s, "/admin/login", dict(username="ghost", password="whatever whatever"), token_from="/admin/login")
check("unknown user -> same generic error", r.status_code == 200 and "Invalid username or password." in r.text)
r = post(s, "/admin/login", dict(username="prateek", password="correct horse battery"), token_from="/admin/login")
check("correct password -> redirect", r.status_code == 303 and r.headers["Location"].endswith("/admin"))
check("session id regenerated on login", s.cookies.get("cmssid") != pre_sid)
r = get(s, "/admin")
check("dashboard renders", r.status_code == 200 and "Test Site" in r.text and "prateek" in r.text)
check("2FA-off notice shown", "Two-factor authentication is off" in r.text)

print("Two-factor setup")
r = post(s, "/admin/security/2fa/start", {}, token_from="/admin/security")
check("start redirects to setup", r.status_code == 303 and r.headers["Location"].endswith("/admin/security/2fa/setup"))
r = get(s, "/admin/security/2fa/setup")
secret = re.search(r'user-select-all">([A-Z2-7 ]+)</code>', r.text).group(1).replace(" ", "")
check("setup shows 32-char secret", len(secret) == 32)
step = int(time.time()) // 30
r = post(s, "/admin/security/2fa/confirm", {"code": "000000"}, token_from="/admin/security/2fa/setup")
check("wrong confirm code rejected", r.status_code == 303 and r.headers["Location"].endswith("/setup"))
r = post(s, "/admin/security/2fa/confirm", {"code": totp(secret, step)}, token_from="/admin/security/2fa/setup")
codes = re.findall(r"<li>([a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4})</li>", r.text)
check("correct code enables 2FA and shows 10 recovery codes", len(codes) == 10, r.status_code)
enc = os.popen("mysql -uroot -N cmse2e -e \"SELECT totp_secret_enc FROM cms_users\"").read().strip()
check("secret stored encrypted (not plain)", enc != "" and secret not in enc)
hashes = os.popen("mysql -uroot -N cmse2e -e \"SELECT code_hash FROM cms_recovery_codes\"").read().split()
check("recovery codes stored only as 64-char hashes", len(hashes) == 10 and all(len(h) == 64 for h in hashes) and not any(c.replace('-', '') in "".join(hashes) for c in codes))

print("Sign in with two-factor")
r = post(s, "/admin/logout", {}, token_from="/admin")
check("logout redirects", r.status_code == 303)
r = get(s, "/admin")
check("signed out after logout", r.status_code == 303 and r.headers["Location"].endswith("/admin/login"))
r = post(s, "/admin/login", dict(username="prateek", password="correct horse battery"), token_from="/admin/login")
check("password ok -> 2FA step", r.status_code == 303 and r.headers["Location"].endswith("/admin/login/2fa"))
r = get(s, "/admin")
check("not signed in between password and code", r.status_code == 303 and r.headers["Location"].endswith("/admin/login"))
r = post(s, "/admin/login/2fa", {"code": "123456"}, token_from="/admin/login/2fa")
check("wrong code rejected", r.status_code == 200 and "not accepted" in r.text)
r = post(s, "/admin/login/2fa", {"code": totp(secret, step)}, token_from="/admin/login/2fa")
check("replay of the code used at setup rejected", r.status_code == 200 and "not accepted" in r.text)
r = post(s, "/admin/login/2fa", {"code": totp(secret, step + 1)}, token_from="/admin/login/2fa")
check("fresh code accepted", r.status_code == 303 and r.headers["Location"].endswith("/admin"), r.status_code)
check("dashboard after 2FA", "Signed in as" in get(s, "/admin").text)
r = post(s, "/admin/logout", {}, token_from="/admin")
r = post(s, "/admin/login", dict(username="prateek", password="correct horse battery"), token_from="/admin/login")
r = post(s, "/admin/login/2fa", {"code": codes[0]}, token_from="/admin/login/2fa")
check("recovery code signs in", r.status_code == 303 and r.headers["Location"].endswith("/admin"))
post(s, "/admin/logout", {}, token_from="/admin")
post(s, "/admin/login", dict(username="prateek", password="correct horse battery"), token_from="/admin/login")
r = post(s, "/admin/login/2fa", {"code": codes[0]}, token_from="/admin/login/2fa")
check("used recovery code rejected", r.status_code == 200 and "not accepted" in r.text)
r = post(s, "/admin/login/2fa", {"code": codes[1].upper()}, token_from="/admin/login/2fa")
check("second recovery code works (case-insensitive)", r.status_code == 303)

print("Security settings")
r = post(s, "/admin/security/2fa/disable", {"current_password": "wrong wrong wrong"}, token_from="/admin/security")
check("disable 2FA needs correct password", "incorrect" in get(s, "/admin/security").text)
r = post(s, "/admin/security/password", {"current_password": "correct horse battery", "new_password": "short", "confirm_password": "short"}, token_from="/admin/security")
check("short new password rejected", "at least 12" in get(s, "/admin/security").text)
r = post(s, "/admin/security/password", {"current_password": "bad bad bad bad", "new_password": "a brand new passphrase", "confirm_password": "a brand new passphrase"}, token_from="/admin/security")
check("wrong current password rejected", "incorrect" in get(s, "/admin/security").text)
sid_before = s.cookies.get("cmssid")
r = post(s, "/admin/security/password", {"current_password": "correct horse battery", "new_password": "a brand new passphrase", "confirm_password": "a brand new passphrase"}, token_from="/admin/security")
check("password changed, still signed in, new session id", "Password changed" in get(s, "/admin/security").text and s.cookies.get("cmssid") != sid_before)
r = post(s, "/admin/security/2fa/disable", {"current_password": "a brand new passphrase"}, token_from="/admin/security")
check("2FA disabled with correct password", "now off" in get(s, "/admin/security").text)
post(s, "/admin/logout", {}, token_from="/admin")
r = post(s, "/admin/login", dict(username="prateek", password="correct horse battery"), token_from="/admin/login")
check("old password no longer works", r.status_code == 200 and "Invalid" in r.text)
r = post(s, "/admin/login", dict(username="prateek", password="a brand new passphrase"), token_from="/admin/login")
check("new password works (no 2FA step)", r.status_code == 303 and r.headers["Location"].endswith("/admin"))

print("Session isolation and throttling")
other = new()
check("a different client is not signed in", get(other, "/admin").status_code == 303)
forged = new(); forged.cookies.set("cmssid", "attackersuppliedid1234567890abcd", domain="127.0.0.1", path="/")
get(forged, "/admin/login")
check("unknown session id is replaced (strict mode)", [c.value for c in forged.cookies if c.name == "cmssid"][-1] != "attackersuppliedid1234567890abcd")
t = new()
codes_seen = []
for i in range(6):
    r = post(t, "/admin/login", dict(username="prateek", password="wrong wrong wrong %d" % i), token_from="/admin/login")
    codes_seen.append(r.status_code)
check("6th failed attempt is throttled (429)", codes_seen[:5] == [200] * 5 and codes_seen[5] == 429, codes_seen)
r = post(t, "/admin/login", dict(username="prateek", password="a brand new passphrase"), token_from="/admin/login")
check("even the correct password is blocked during lockout", r.status_code == 429)
log = open(ROOT + "/user/logs/app.log").read()
check("security events logged", "login_failed" in log and "login_blocked" in log and "password_changed" in log)
check("no passwords/secrets in log", "correct horse" not in log and "passphrase" not in log and secret not in log)
php_err = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
check("no PHP warnings/errors logged", not php_err, php_err)

print("\n%d passed, %d failed" % (res["pass"], res["fail"]))
sys.exit(1 if res["fail"] else 0)
