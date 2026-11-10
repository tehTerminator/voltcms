"""Upgrade + HTTP end-to-end tests: install Phase 1, upgrade to the current release, then test content (Phase 2) and themes (Phase 3).

Driven by tests/e2e_upgrade.sh (sandbox paths). Needs python3 + requests, php, MySQL/MariaDB.
Usage: e2e_upgrade.py partA   (installs Phase 1 through the installer)
       e2e_upgrade.py partB   (after the new files were copied over: upgrade + content flows)
       e2e_upgrade.py partC   (theme engine flows; run after partB)
"""
import os, re, sys, time
import requests

BASE = os.environ.get("CMS_E2E_BASE", "http://127.0.0.1:8099")
ROOT = os.environ.get("CMS_E2E_ROOT", "/tmp/e2e2")
PW = "correct horse battery"
VERSION = open(os.environ.get("CMS_E2E_NEW", "/home/claude/cms") + "/core/VERSION").read().strip()
res = {"pass": 0, "fail": 0}

def check(name, ok, extra=""):
    res["pass" if ok else "fail"] += 1
    print(("  ok   " if ok else "  FAIL ") + name + ((" -> " + str(extra)[:300]) if (not ok and extra != "") else ""))

def csrf(html):
    m = re.search(r'name="_csrf" value="([0-9a-f]{64})"', html)
    return m.group(1) if m else ""

def get(s, p, **k): return s.get(BASE + p, allow_redirects=False, **k)
def post(s, p, data, token_from=None, **k):
    if token_from is not None:
        data = dict(data, _csrf=csrf(get(s, token_from).text))
    return s.post(BASE + p, data=data, allow_redirects=False, **k)

def login():
    s = requests.Session()
    r = post(s, "/admin/login", dict(username="prateek", password=PW), token_from="/admin/login")
    assert r.status_code == 303, r.text[:200]
    return s

def new_item(s, **f):
    data = dict(title="Untitled", type="article", status="published", body="<p>body</p>", slug="", visibility="public",
                parent_id="", excerpt="", meta_description="", custom_css="", custom_js="", view_password="")
    data.update(f)
    r = post(s, "/admin/content/new", data, token_from="/admin/content/new?type=" + data["type"])
    m = re.search(r"/admin/content/(\d+)$", r.headers.get("Location", ""))
    return (int(m.group(1)) if m else None), r

def partA():
    print("Part A: install Phase 1")
    s = requests.Session()
    good = dict(db_host="127.0.0.1", db_port="3306", db_name="cmse2e", db_user="cms", db_pass="cmspass", prefix="cms_",
                site_name="Test Site", admin_username="prateek", admin_email="a@example.com",
                admin_password=PW, admin_password2=PW)
    r = post(s, "/install.php", good, token_from="/install.php")
    check("Phase 1 installs", "Installation complete" in r.text, r.text[:200])
    check("Phase 1 version recorded", "0.1.0-phase1" in open(ROOT + "/user/config/app.php").read())
    r = get(s, "/")
    check("Phase 1 home page works", r.status_code == 200 and "installed" in r.text)

def partB():
    print("Upgrade from Phase 1")
    anon = requests.Session()
    r = get(anon, "/")
    check("public site shows 'updating' (503) until the update is applied", r.status_code == 503 and "being updated" in r.text and "Retry-After" in r.headers)
    r = get(anon, "/some-page")
    check("same for content URLs", r.status_code == 503)
    s = login()
    r = get(s, "/admin")
    check("admin redirects to the update screen", r.status_code == 303 and r.headers["Location"].endswith("/admin/update"))
    r = get(s, "/admin/content")
    check("content area is gated too", r.status_code == 303 and r.headers["Location"].endswith("/admin/update"))
    r = get(s, "/admin/update")
    check("update screen lists the pending migration", r.status_code == 200 and "0002_content" in r.text and "0.1.0-phase1" in r.text)
    r = get(anon, "/install.php")
    check("re-uploaded install.php is locked", r.status_code == 403 and "already installed" in r.text.lower())
    r = post(s, "/admin/update/apply", {}, token_from="/admin/update")
    check("apply update redirects to dashboard", r.status_code == 303 and r.headers["Location"].endswith("/admin"))
    r = get(s, "/admin")
    check("dashboard works after update, shows counts, warns about install.php", r.status_code == 200 and "Published" in r.text and "Delete install.php" in r.text)
    check("installed_version updated in app.php", VERSION in open(ROOT + "/user/config/app.php").read(), VERSION)
    r = get(anon, "/")
    check("public site is back (200)", r.status_code == 200 and "Nothing has been published yet" in r.text)
    r = get(s, "/admin/update")
    check("update screen no longer reachable", r.status_code == 303 and r.headers["Location"].endswith("/admin"))

    print("Admin access control")
    r = get(anon, "/admin/content")
    check("content list requires sign-in", r.status_code == 303 and r.headers["Location"].endswith("/admin/login"))
    r = anon.post(BASE + "/admin/content/new", data={"title": "x"}, allow_redirects=False)
    check("anonymous POST refused", r.status_code in (303, 403))
    r = s.post(BASE + "/admin/content/new", data={"title": "x"}, allow_redirects=False)
    check("signed-in POST without CSRF token -> 403", r.status_code == 403)
    r = get(anon, "/admin/assets/admin.js")
    check("admin.js served without sign-in with JS content type", r.status_code == 200 and "javascript" in r.headers["Content-Type"] and "beforeunload" in r.text)
    check("admin pages send CSP with script-src 'self'", "script-src 'self'" in get(s, "/admin/content").headers.get("Content-Security-Policy", ""))

    print("Create, preview, publish")
    css = "body{background:#fafafa}"
    js = "window.__ok=1;"
    xss_body = '<p>Hello <b>world</b></p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">bad</a><div class="row" style="color:red;position:fixed">x</div>'
    aid, r = new_item(s, title="My First <b>Post</b>", status="draft", body=xss_body, custom_css=css, custom_js=js)
    check("draft created", aid is not None and r.status_code == 303, r.text[:300])
    body_file = ROOT + "/user/content/posts/%d.html" % aid
    check("body file written", os.path.isfile(body_file))
    stored = open(body_file).read()
    check("stored body is sanitised", "<script" not in stored and "onerror" not in stored and "javascript:" not in stored and "position" not in stored and "color: red" in stored, stored)
    check("slug generated from title", get(s, "/admin/content/%d" % aid).text.count('value="my-first-b-post-b"') == 1 or "my-first" in get(s, "/admin/content/%d" % aid).text)
    r = get(s, "/admin/content")
    check("list shows the item with an escaped title", "My First &lt;b&gt;Post&lt;/b&gt;" in r.text and "<b>Post</b>" not in r.text)
    slug = re.search(r'/admin/content/%d/preview' % aid, r.text) and re.search(r'<div class="text-secondary small[^"]*">/([^<]+)</div>', r.text).group(1)
    r = get(anon, "/" + slug)
    check("draft is 404 to visitors", r.status_code == 404)
    r = get(anon, "/")
    check("draft not on the home page", "My First" not in r.text)
    r = get(s, "/admin/content/%d/preview" % aid)
    check("preview works for drafts, banner shown, no cache, noindex", r.status_code == 200 and "Preview." in r.text and "no-store" in r.headers.get("Cache-Control", "") and "noindex" in r.headers.get("X-Robots-Tag", ""))
    check("preview body sanitised and title escaped", "<script>alert" not in r.text and "onerror" not in r.text and "My First &lt;b&gt;Post&lt;/b&gt;" in r.text)
    r = get(anon, "/admin/content/%d/preview" % aid)
    check("preview requires sign-in", r.status_code == 303)

    r = post(s, "/admin/content/%d" % aid, dict(title="My First Post", type="article", status="published", visibility="public", slug="my-first-post",
             parent_id="", excerpt="", meta_description="A short description", custom_css=css, custom_js=js, view_password="", noindex="",
             body=stored), token_from="/admin/content/%d" % aid)
    check("publish via edit form", r.status_code == 303)
    r = get(anon, "/my-first-post")
    check("published page is public", r.status_code == 200 and "My First Post" in r.text)
    check("meta description rendered", '<meta name="description" content="A short description">' in r.text)
    check("html lang attribute present", '<html lang="en">' in r.text)
    csp = r.headers.get("Content-Security-Policy", "")
    nonce = re.search(r"'nonce-([^']+)'", csp)
    check("public CSP carries a per-request nonce", nonce is not None and "script-src" in csp and "default-src 'none'" in csp)
    n = nonce.group(1) if nonce else "?"
    check("per-page inline CSS/JS is no longer rendered (replaced by linked code assets in Phase 4A)", css not in r.text and js not in r.text and "<style nonce=" not in r.text)
    r2 = get(anon, "/my-first-post")
    check("nonce differs per request", re.search(r"'nonce-([^']+)'", r2.headers["Content-Security-Policy"]).group(1) != n)
    check("noindex absent on a normal page", "noindex" not in r.text)
    r = get(anon, "/My-First-POST")
    check("different case redirects (301) to the canonical URL", r.status_code == 301 and r.headers["Location"].endswith("/my-first-post"))
    r = get(anon, "/")
    check("published article on the home page", "My First Post" in r.text and "/my-first-post" in r.text)
    check("excerpt shown in list (auto-filled)", "Hello world" in r.text)

    print("Slugs, errors, 404")
    r = post(s, "/admin/content/new", dict(title="Dup", slug="admin", type="page", status="draft", body=""), token_from="/admin/content/new")
    check("reserved slug rejected with 422 and message", r.status_code == 422 and "reserved" in r.text)
    check("form keeps typed values after an error", 'value="Dup"' in r.text)
    r = post(s, "/admin/content/new", dict(title="", type="page", status="draft", body="keep me"), token_from="/admin/content/new")
    check("empty title rejected, body kept in the form", r.status_code == 422 and "keep me" in r.text)
    bid, _ = new_item(s, title="My First Post")
    check("duplicate title gets -2 slug", bid is not None and "/my-first-post-2" in get(anon, "/").text)
    r = get(anon, "/does-not-exist")
    check("unknown slug -> themed 404", r.status_code == 404 and "Page not found" in r.text and "noindex" in r.text)
    r = get(anon, "/a/b/c")
    check("deep path -> 404", r.status_code == 404)
    r = get(anon, "/%2e%2e/etc")
    check("traversal-ish path -> 404/403", r.status_code in (403, 404))
    r = anon.post(BASE + "/my-first-post", data={}, allow_redirects=False)
    check("POST to a public page -> 405", r.status_code == 405 and "GET" in r.headers.get("Allow", ""))

    print("Unicode slug")
    hid, _ = new_item(s, title="भारतीय संविधान", body="<p>अनुच्छेद 21</p>")
    r = get(anon, "/%E0%A4%AD%E0%A4%BE%E0%A4%B0%E0%A4%A4%E0%A5%80%E0%A4%AF-%E0%A4%B8%E0%A4%82%E0%A4%B5%E0%A4%BF%E0%A4%A7%E0%A4%BE%E0%A4%A8")
    check("Hindi slug resolves and Hindi body is intact", r.status_code == 200 and "अनुच्छेद 21" in r.text and "भारतीय संविधान" in r.text, r.status_code)
    check("home links to the percent-encoded Hindi URL", "%E0%A4%AD" in get(anon, "/").text)

    print("Pagination")
    for i in range(12):
        new_item(s, title="Bulk article %02d" % i, body="<p>b</p>")
    r1 = get(anon, "/"); r2 = get(anon, "/?page=2"); r9 = get(anon, "/?page=99")
    check("home paginates (10 per page)", r1.text.count("<article") == 10 and "Page 1 of 2" in r1.text and r2.status_code == 200 and "Page 2 of 2" in r2.text)
    check("page beyond the end -> 404", r9.status_code == 404)
    check("garbage page parameter -> page 1", get(anon, "/?page=abc").text.count("<article") == 10)

    print("Book articles")
    root, _ = new_item(s, title="Constitution Notes", type="book", body="<p>Intro</p>")
    c1, _ = new_item(s, title="Part One", type="book", parent_id=str(root), body="<p>one</p>")
    c2, _ = new_item(s, title="Part Two", type="book", parent_id=str(root), body="<p>two</p>")
    r = get(anon, "/constitution-notes")
    check("book root lists its children", r.status_code == 200 and "Part One" in r.text and "Part Two" in r.text and "In this section" in r.text)
    r = get(anon, "/constitution-notes/part-one")
    check("child (at its full address) shows breadcrumb and next link", 'breadcrumb' in r.text and 'href="/constitution-notes"' in r.text and 'rel="next"' in r.text and "Part Two" in r.text)
    r = post(s, "/admin/content/%d/trash" % root, {}, token_from="/admin/content")
    check("book with children cannot be trashed", "child pages" in get(s, "/admin/content").text)
    r = post(s, "/admin/content/%d/move" % c2, {"dir": "up"}, token_from="/admin/content?type=book")
    r = get(anon, "/constitution-notes")
    check("move up changes order on the public page", r.text.index("Part Two") < r.text.index("Part One"))
    r = get(s, "/admin/content?type=book")
    check("book outline view shows move buttons", 'aria-label="Move up"' in r.text)
    r = post(s, "/admin/content/%d" % root, dict(title="Constitution Notes", type="book", status="published", visibility="public", slug="", parent_id=str(c1),
             body="x", excerpt="", meta_description="", custom_css="", custom_js="", view_password=""), token_from="/admin/content/%d" % root)
    check("cannot move a book under its own child", r.status_code == 422 and "under itself" in r.text)

    print("Private pages")
    pid, _ = new_item(s, title="Family Papers", visibility="private", view_password="open-sesame-1", body="<p>TOP SECRET CONTENT</p>")
    check("private page needs a password", new_item(s, title="No pw", visibility="private")[1].status_code == 422)
    v = requests.Session()
    r = get(v, "/family-papers")
    check("visitor sees only the password form", r.status_code == 200 and "This page is private" in r.text and "TOP SECRET" not in r.text)
    check("private form is noindex and not cacheable", "noindex" in r.headers.get("X-Robots-Tag", "") and "no-store" in r.headers.get("Cache-Control", ""))
    r = post(v, "/family-papers", {"password": "wrong"}, token_from="/family-papers")
    check("wrong password rejected", r.status_code == 200 and "not correct" in r.text and "TOP SECRET" not in r.text)
    r = v.post(BASE + "/family-papers", data={"password": "open-sesame-1"}, allow_redirects=False)
    check("password without CSRF token refused", "TOP SECRET" not in r.text and r.status_code != 303)
    r = post(v, "/family-papers", {"password": "open-sesame-1"}, token_from="/family-papers")
    check("correct password redirects", r.status_code == 303)
    r = get(v, "/family-papers")
    check("unlocked visitor sees the content (noindex, no-store)", r.status_code == 200 and "TOP SECRET CONTENT" in r.text and 'name="robots" content="noindex' in r.text and "no-store" in r.headers.get("Cache-Control", ""))
    other = requests.Session()
    check("another visitor is still locked", "TOP SECRET" not in get(other, "/family-papers").text)
    check("signed-in admin can view private pages directly", "TOP SECRET CONTENT" in get(s, "/family-papers").text)
    check("private page absent from home, children lists", "Family Papers" not in get(anon, "/").text)
    t = requests.Session()
    codes = [post(t, "/family-papers", {"password": "bad%d" % i}, token_from="/family-papers").status_code for i in range(6)]
    check("page-password guessing is throttled (429)", codes[-1] == 429 and 429 not in codes[:5], codes)

    print("Revisions, trash, bulk, integrity")
    rid, _ = new_item(s, title="Revision Test", body="<p>v1</p>")
    for ver in ("v2", "v3"):
        post(s, "/admin/content/%d" % rid, dict(title="Revision Test", type="article", status="published", visibility="public", slug="revision-test", parent_id="",
             body="<p>%s</p>" % ver, excerpt="x", meta_description="", custom_css="", custom_js="", view_password=""), token_from="/admin/content/%d" % rid)
    r = get(s, "/admin/content/%d/revisions" % rid)
    revs = re.findall(r'/revisions/(\d+)/restore', r.text)
    check("two earlier versions listed", len(revs) == 2, revs)
    oldest = sorted(map(int, revs))[0]
    post(s, "/admin/content/%d/revisions/%d/restore" % (rid, oldest), {}, token_from="/admin/content/%d/revisions" % rid)
    check("restoring brings the oldest body back publicly", "<p>v1</p>" in get(anon, "/revision-test").text)
    check("restore itself is recorded (3 revisions)", len(re.findall(r'/revisions/(\d+)/restore', get(s, "/admin/content/%d/revisions" % rid).text)) == 3)

    post(s, "/admin/content/%d/trash" % rid, {}, token_from="/admin/content")
    check("trashed page is 404 publicly", get(anon, "/revision-test").status_code == 404)
    check("trashed page listed in Trash", "Revision Test" in get(s, "/admin/content?status=trash").text)
    post(s, "/admin/content/%d/restore" % rid, {}, token_from="/admin/content?status=trash")
    check("restored page is public again", get(anon, "/revision-test").status_code == 200)
    post(s, "/admin/content/%d/trash" % rid, {}, token_from="/admin/content")
    r = post(s, "/admin/content/%d/purge" % rid, {}, token_from="/admin/content?status=trash")
    arch = [d for d in os.listdir(ROOT + "/user/backups/deleted") if d.startswith("%d-" % rid)]
    check("purge archives the files", len(arch) == 1 and os.path.isfile(ROOT + "/user/backups/deleted/%s/body.html" % arch[0]) and os.path.isfile(ROOT + "/user/backups/deleted/%s/meta.json" % arch[0]) and not os.path.isfile(ROOT + "/user/content/posts/%d.html" % rid))

    d1, _ = new_item(s, title="Bulk draft one", status="draft")
    d2, _ = new_item(s, title="Bulk draft two", status="draft")
    post(s, "/admin/content/bulk", {"bulk_action": "publish", "ids[]": [str(d1), str(d2)]}, token_from="/admin/content")
    check("bulk publish makes both public", get(anon, "/bulk-draft-one").status_code == 200 and get(anon, "/bulk-draft-two").status_code == 200)
    post(s, "/admin/content/bulk", {"bulk_action": "trash", "ids[]": [str(d1), str(d2)]}, token_from="/admin/content")
    check("bulk trash hides both", get(anon, "/bulk-draft-one").status_code == 404)
    r = post(s, "/admin/content/bulk", {"bulk_action": "", "ids[]": []}, token_from="/admin/content")
    check("bulk with nothing selected is handled", r.status_code == 303)

    os.remove(ROOT + "/user/content/posts/%d.html" % aid)
    open(ROOT + "/user/content/posts/99999.html", "w").write("<p>stray</p>")
    r = get(s, "/admin/content/integrity")
    check("integrity report lists the missing file and the orphan", r.status_code == 200 and "missing body file" in r.text and "99999.html" in r.text)
    check("integrity report did not touch the orphan", os.path.isfile(ROOT + "/user/content/posts/99999.html"))
    check("public page with a missing file -> 503, not a crash", get(anon, "/my-first-post").status_code == 503)
    check("edit form warns about the missing file", "missing on the server" in get(s, "/admin/content/%d" % aid).text)

    print("Search and filters (admin)")
    r = get(s, "/admin/content?q=Bulk+article")
    check("title search", r.status_code == 200 and "Bulk article" in r.text and "Constitution" not in r.text)
    check("type filter", "Constitution Notes" in get(s, "/admin/content?type=book").text and "Bulk article" not in get(s, "/admin/content?type=book").text)
    for i in range(3):
        new_item(s, title="Extra %d" % i, status="draft")
    r = get(s, "/admin/content")
    check("pagination in admin (20 per page)", "Page 1 of 2" in r.text and r.text.count('name="ids[]"') == 20 and 'Page 2 of 2' in get(s, "/admin/content?page=2").text)
    check("garbage filter values are harmless", get(s, "/admin/content?type=%27%3B--&status=zzz&page=-4&q[]=1").status_code == 200)
    check("unknown content id -> 404", get(s, "/admin/content/987654").status_code == 404 and get(s, "/admin/content/abc").status_code == 404)
    check("wrong method -> 405", s.post(BASE + "/admin/content/integrity", data={}, allow_redirects=False).status_code in (403, 405))

    log = open(ROOT + "/user/logs/app.log").read()
    check("page-password failures logged without the password", "page_password_failed" in log and "open-sesame" not in log and "bad0" not in log)
    perr = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
    check("no PHP warnings or errors logged", not perr, perr)

def partC():
    print("Theme engine: base theme output")
    anon = requests.Session()
    s = login()
    css_re = re.compile(r'href="(/assets/base/css/theme\.css\?v=[^"]+)"')
    aid, _ = new_item(s, title="Theme Test Article", body='<table class="table"><tr><td>cell</td></tr></table><p>hello</p>', custom_css="p{color:#111}")
    r = get(anon, "/theme-test-article")
    check("article renders with the base theme", r.status_code == 200 and '<body class="template-article">' in r.text and 'class="cms-content"' in r.text)
    m = css_re.search(r.text)
    check("base theme CSS linked through /assets/base with a version", m is not None and VERSION in m.group(1), r.text[:400])
    check("base theme JS linked with defer", re.search(r'<script src="/assets/base/js/theme\.js\?v=[^"]+" defer></script>', r.text) is not None)
    check("Bootstrap with SRI still present", 'integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"' in r.text)
    check("old per-page CSS field is ignored; nothing inline is rendered", "p{color:#111}" not in r.text and "<style nonce=" not in r.text)
    check("article page does not load the book stylesheet", "templates/book.css" not in r.text)
    root, _ = new_item(s, title="Theme Book", type="book", body="<p>b</p>")
    r = get(anon, "/theme-book")
    check("book page loads its template-specific stylesheet", "/assets/base/css/templates/book.css" in r.text and 'template-book' in r.text)

    print("Theme engine: base asset route")
    url = m.group(1)
    r = get(anon, url)
    check("asset served: 200, css, immutable cache, ETag", r.status_code == 200 and r.headers["Content-Type"].startswith("text/css") and "immutable" in r.headers["Cache-Control"] and "max-age=31536000" in r.headers["Cache-Control"] and r.headers.get("ETag"), r.headers)
    check("asset body is the real file", ".cms-content" in r.text and r.headers.get("X-Content-Type-Options") == "nosniff")
    etag = r.headers["ETag"]
    r2 = get(anon, url, headers={"If-None-Match": etag})
    check("conditional request -> 304 with no body", r2.status_code == 304 and r2.text == "")
    check("without ?v the cache is short", "max-age=3600" in get(anon, "/assets/base/css/theme.css").headers["Cache-Control"])
    rj = get(anon, "/assets/base/js/theme.js")
    check("javascript content type", rj.status_code == 200 and "javascript" in rj.headers["Content-Type"] and "table-responsive" in rj.text)
    check("HEAD returns headers only", requests.head(BASE + "/assets/base/css/theme.css", allow_redirects=False).status_code == 200)
    for p in ["/assets/base/css/nope.css", "/assets/base/theme.json", "/assets/base/layout.php", "/assets/base/", "/assets/base/css/", "/assets/base/%2e%2e/%2e%2e/VERSION", "/assets/base/css/%2e%2e/%2e%2e/%2e%2e/user/db.php", "/assets/base/..%2f..%2fVERSION"]:
        rr = get(anon, p)
        check("asset refused: " + p, rr.status_code in (403, 404) and rr.text.strip() != VERSION and "<?php" not in rr.text, rr.status_code)
    check("POST to an asset -> 405", anon.post(BASE + "/assets/base/css/theme.css", data={}, allow_redirects=False).status_code == 405)
    check("assets are served even while the CMS needs no update (no DB dependency)", get(anon, "/assets/base/css/theme.css").status_code == 200)

    print("Themes admin")
    check("themes screen requires sign-in", get(anon, "/admin/themes").status_code == 303)
    r = get(s, "/admin/themes")
    check("themes screen lists the base theme as active", r.status_code == 200 and "Base" in r.text and "Active" in r.text and "Create a child theme" in r.text)
    check("POST without CSRF refused", s.post(BASE + "/admin/themes/create", data={"title": "x", "slug": "x"}, allow_redirects=False).status_code == 403)
    for bad in [("Bad", "Bad Slug"), ("Bad", "base"), ("", "ok-slug"), ("T", "../evil"), ("T", "UPPER_case")]:
        post(s, "/admin/themes/create", {"title": bad[0], "slug": bad[1]}, token_from="/admin/themes")
    check("invalid folder names create nothing", not os.path.exists(ROOT + "/user/themes/bad slug") and not os.path.exists(ROOT + "/user/evil") and not os.path.exists(ROOT + "/user/themes/base"))
    post(s, "/admin/themes/create", {"title": "Test Child", "slug": "test-child"}, token_from="/admin/themes")
    tc = ROOT + "/user/themes/test-child"
    check("child theme skeleton created", all(os.path.isfile(tc + f) for f in ["/theme.json", "/assets/css/child.css", "/README.txt"]) and os.path.isdir(tc + "/templates") and os.path.isdir(tc + "/partials"))
    check("theme files are world-readable (static serving)", oct(os.stat(tc + "/assets/css/child.css").st_mode & 0o777) == "0o644" and oct(os.stat(tc + "/theme.json").st_mode & 0o777) == "0o644")
    check("manifest is valid JSON with parent base", __import__("json").load(open(tc + "/theme.json"))["parent"] == "base")
    r = get(s, "/admin/themes")
    check("new theme listed, inactive, activatable", "Test Child" in r.text and 'value="test-child"' in r.text and "Cannot be activated" not in r.text)
    post(s, "/admin/themes/create", {"title": "Again", "slug": "test-child"}, token_from="/admin/themes")
    check("duplicate folder refused", "already exists" in get(s, "/admin/themes").text)

    print("Theme engine: activating and overriding")
    r = post(s, "/admin/themes/activate", {"name": "test-child"}, token_from="/admin/themes")
    check("activate redirects", r.status_code == 303)
    check("themes screen shows it active", re.search(r"Test Child\s*<span class=\"badge text-bg-success ms-1\">Active", get(s, "/admin/themes").text) is not None)
    check("dashboard shows the active theme", "test-child" in get(s, "/admin").text)
    r = get(anon, "/theme-test-article")
    m2 = re.search(r'href="(/user/themes/test-child/assets/css/child\.css\?v=\d+)"', r.text)
    check("child stylesheet linked directly under /user/themes", m2 is not None, r.text[:300])
    check("base stylesheet still loads before the child's", r.text.index("/assets/base/css/theme.css") < r.text.index("/user/themes/test-child/assets/css/child.css"))
    rc = get(anon, m2.group(1)) if m2 else None
    check("child asset is served as a static file", rc is not None and rc.status_code == 200 and "Styles for the" in rc.text, rc.status_code if rc is not None else "")

    open(tc + "/partials/footer.php", "w").write('<footer id="kid-footer">Custom footer for <?= e($site["name"]) ?> (<?= e(theme_info()["title"]) ?>)</footer>')
    open(tc + "/assets/img/logo.svg" if os.path.isdir(tc + "/assets/img") else "/dev/null", "w") if False else None
    os.makedirs(tc + "/assets/img", exist_ok=True)
    open(tc + "/assets/img/logo.svg", "w").write('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>')
    open(tc + "/templates/article.php", "w").write('<article id="kid-article"><img src="<?= e(get_theme_url("img/logo.svg")) ?>" alt=""><link rel="x" href="<?= e(get_theme_url("css/theme.css")) ?>"><h1><?= e($item["title"]) ?></h1><div class="cms-content"><?= $body ?></div></article>')
    r = get(anon, "/theme-test-article")
    check("child partial overrides the base footer; base header kept", 'id="kid-footer"' in r.text and "Custom footer for Test Site (Test Child)" in r.text and "navbar-brand" in r.text and "&copy;" not in r.text)
    check("child template overrides the base article template", 'id="kid-article"' in r.text and "Theme Test Article" in r.text)
    check("get_theme_url: child file -> /user/themes URL", re.search(r'src="/user/themes/test-child/assets/img/logo\.svg\?v=\d+"', r.text) is not None)
    check("get_theme_url: base file -> /assets/base URL", 'href="/assets/base/css/theme.css?v=' in r.text.split('rel="x"')[1][:120])
    check("other templates still come from base (page, book)", "kid-article" not in get(anon, "/theme-book").text and "Custom footer" in get(anon, "/theme-book").text)
    check("404 pages use the child theme too", 'id="kid-footer"' in get(anon, "/no-such-page").text and get(anon, "/no-such-page").status_code == 404)
    check("child svg is served statically", get(anon, "/user/themes/test-child/assets/img/logo.svg").status_code == 200)

    open(tc + "/assets/css/templates/article.css", "w").write("/* article only */")
    check("template-specific stylesheet loads on that template", "/user/themes/test-child/assets/css/templates/article.css" in get(anon, "/theme-test-article").text)
    pid, _ = new_item(s, title="About Us", type="page", body="<p>about</p>")
    check("template-specific stylesheet is absent on other templates", "templates/article.css" not in get(anon, "/about-us").text)
    open(tc + "/templates/page-about-us.php", "w").write('<h1 id="about-special">Special about page: <?= e($item["title"]) ?></h1>')
    check("slug-specific template page-{slug}.php wins", 'id="about-special"' in get(anon, "/about-us").text and "template-page-about-us" in get(anon, "/about-us").text)
    dr, _ = new_item(s, title="Draft For Preview", status="draft", body="<p>draft body</p>")
    check("admin preview uses the active theme", "Custom footer" in get(s, "/admin/content/%d/preview" % dr).text)
    pv, _ = new_item(s, title="Theme Private", visibility="private", view_password="open-sesame-2", body="<p>secret</p>")
    check("private password form uses the theme", "kid-footer" in get(anon, "/theme-private").text)

    print("Theme engine: failures fall back")
    good_article = open(tc + "/templates/article.php").read()
    open(tc + "/templates/article.php", "w").write("<?php if ( { ?>")
    r = get(anon, "/theme-test-article")
    check("a child template with a syntax error falls back to base (200, content intact)", r.status_code == 200 and "Theme Test Article" in r.text and "kid-article" not in r.text and "navbar-brand" in r.text, r.status_code)
    open(tc + "/templates/article.php", "w").write('<?php throw new RuntimeException("kaboom-secret-detail"); ?>')
    r = get(anon, "/theme-test-article")
    check("a child template that throws falls back and does not leak the error", r.status_code == 200 and "kaboom" not in r.text and "Theme Test Article" in r.text)
    log = open(ROOT + "/user/logs/app.log").read()
    check("failures are logged for the administrator", "Theme render failed" in log and "test-child" in log)
    open(tc + "/templates/article.php", "w").write("<?php if ( { ?>")
    r = get(s, "/admin/themes")
    check("themes screen flags the broken file", "PHP syntax error in templates/article.php" in r.text and "Cannot be activated" in r.text)
    post(s, "/admin/themes/create", {"title": "Second", "slug": "second"}, token_from="/admin/themes")
    open(ROOT + "/user/themes/second/templates/page.php", "w").write("<?php nope nope ?>")
    r = post(s, "/admin/themes/activate", {"name": "second"}, token_from="/admin/themes")
    check("activating a theme with a syntax error is refused", "was not activated" in get(s, "/admin/themes").text and "syntax error" in get(s, "/admin/themes").text)
    check("the previously active theme stays active", "Custom footer" in get(anon, "/theme-book").text)
    for bad in ["../core", "base/../x", "nonexistent", "Test Child", ""]:
        post(s, "/admin/themes/activate", {"name": bad}, token_from="/admin/themes")
    check("bad theme names cannot be activated", "Custom footer" in get(anon, "/theme-book").text)
    open(tc + "/templates/article.php", "w").write(good_article)
    check("fixing the file restores the child template", 'id="kid-article"' in get(anon, "/theme-test-article").text)

    open(tc + "/theme.json", "w").write("{broken")
    r = get(anon, "/theme-book")
    check("a manifest broken after activation does not take the site down; its declared assets simply stop loading", r.status_code == 200 and "Custom footer" in r.text and "child.css" not in r.text and "/assets/base/css/theme.css" in r.text)
    check("the themes screen reports the broken manifest", "not valid JSON" in get(s, "/admin/themes").text)
    open(tc + "/theme.json", "w").write('{"name":"Test Child","version":"1.0.0","parent":"base","assets":{"css":["css/child.css"],"js":[]}}')

    print("Theme engine: static files and PHP exposure")
    for p in ["/user/themes/test-child/templates/article.php", "/user/themes/test-child/partials/footer.php", "/user/themes/test-child/layout.php"]:
        rr = get(anon, p)
        check("theme PHP not downloadable: " + p, rr.status_code in (403, 404) and "<?php" not in rr.text and "kid-article" not in rr.text, rr.status_code)

    print("Theme engine: missing active theme, switching back")
    import shutil
    shutil.rmtree(ROOT + "/user/themes/second")
    shutil.rmtree(tc)
    r = get(anon, "/theme-test-article")
    check("deleting the active theme folder does not break the site (base used)", r.status_code == 200 and "navbar-brand" in r.text and "Theme Test Article" in r.text and "kid-footer" not in r.text)
    check("themes screen warns that the active theme is missing", "could not be found" in get(s, "/admin/themes").text)
    post(s, "/admin/themes/activate", {"name": "base"}, token_from="/admin/themes")
    page = get(s, "/admin/themes").text
    check("switching back to base confirms and clears the missing-theme warning", "base theme is now active" in page.lower() and "could not be found" not in page)
    check("base css linked again", "/assets/base/css/theme.css" in get(anon, "/theme-test-article").text)
    check("settings keep active_theme=base", os.popen("mysql -uroot -N cmse2e -e \"SELECT setting_value FROM cms_settings WHERE setting_key='active_theme'\"").read().strip() == "base")

    perr = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
    check("no stray PHP warnings or errors in the PHP error log", not perr, perr)

if __name__ == "__main__":
    {"partA": partA, "partB": partB, "partC": lambda: partC()}[sys.argv[1]]()
    print("\n%d passed, %d failed" % (res["pass"], res["fail"]))
    sys.exit(1 if res["fail"] else 0)
