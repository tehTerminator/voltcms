"""Phase 4A end-to-end: install the Phase 3 release, give it per-page custom CSS/JS, upgrade to the current code,
then test conversion, settings, media, code assets, tags and the editor. Driven by tests/e2e_phase4a.sh."""
import os, re, sys, json
import requests

os.environ.setdefault("CMS_E2E_ROOT", "/tmp/e2e4")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_upgrade import BASE, ROOT, PW, VERSION, check, res, get, post, csrf, login  # noqa: E402

FX = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures")

def fx(name): return open(os.path.join(FX, name), "rb").read()

def item(s, **f):
    data = dict(title="Untitled", type="article", status="published", body="<p>body</p>", slug="", visibility="public", parent_id="",
                excerpt="", meta_description="", seo_title="", og_image_media_id="", tags="", view_password="")
    data.update(f)
    r = post(s, "/admin/content/new", data, token_from="/admin/content/new?type=" + data["type"])
    m = re.search(r"/admin/content/(\d+)$", r.headers.get("Location", ""))
    return (int(m.group(1)) if m else None), r

def edit(s, cid, **over):
    page = get(s, "/admin/content/%d" % cid).text
    def val(name):
        m = re.search(r'name="%s"[^>]*value="([^"]*)"' % name, page)
        return m.group(1) if m else ""
    body = re.search(r'<textarea[^>]*name="body"[^>]*>(.*?)</textarea>', page, re.S).group(1)
    import html
    data = dict(title=html.unescape(val("title")), type="article", status="published", visibility="public", slug=html.unescape(val("slug")), parent_id="",
                body=html.unescape(body), excerpt="", meta_description="", seo_title="", og_image_media_id="", tags="", view_password="")
    data.update(over)
    return post(s, "/admin/content/%d" % cid, data, token_from="/admin/content/%d" % cid)

def upload(s, files):
    tok = csrf(get(s, "/admin/media").text)
    return s.post(BASE + "/admin/media/upload", data={"_csrf": tok}, files=files, allow_redirects=False)

def partA():
    print("Part A: Phase 3 baseline with per-page custom CSS/JS")
    s = requests.Session()
    good = dict(db_host="127.0.0.1", db_port="3306", db_name="cmse2e", db_user="cms", db_pass="cmspass", prefix="cms_", site_name="Test Site",
                admin_username="prateek", admin_email="a@example.com", admin_password=PW, admin_password2=PW)
    r = post(s, "/install.php", good, token_from="/install.php")
    check("Phase 3 installs", "Installation complete" in r.text)
    a = login()
    base = dict(title="Legacy Styled", type="article", status="published", visibility="public", slug="", parent_id="", excerpt="", meta_description="", view_password="",
                body="<p>legacy body</p>", custom_css="body{background:#eef}", custom_js="window.__legacy=1;")
    r = post(a, "/admin/content/new", base, token_from="/admin/content/new?type=article")
    check("Phase 3 saves per-page custom code", r.status_code == 303)
    r = post(a, "/admin/content/new", dict(base, title="Legacy Bad Code", custom_css="<?php system('x'); ?>", custom_js=""), token_from="/admin/content/new?type=article")
    check("second legacy page created", r.status_code == 303)
    page = get(requests.Session(), "/legacy-styled").text
    check("Phase 3 renders it inline with a nonce", re.search(r'<style nonce="[^"]+">body\{background:#eef\}</style>', page) is not None)

def partB():
    anon = requests.Session()
    print("Upgrade 0.3 -> current")
    check("site shows 'updating' until the update is applied", get(anon, "/").status_code == 503)
    s = login()
    check("admin redirects to the update screen", get(s, "/admin").headers.get("Location", "").endswith("/admin/update"))
    r = get(s, "/admin/update")
    check("update screen lists all new migrations", all(m in r.text for m in ["0003_media", "0004_tags", "0005_content_seo", "0006_code_assets", "0007_convert_custom_code", "0008_search_index"]))
    r = post(s, "/admin/update/apply", {}, token_from="/admin/update")
    check("update applies cleanly", r.status_code == 303 and r.headers["Location"].endswith("/admin"), r.text[:200])
    check("version recorded", VERSION in open(ROOT + "/user/config/app.php").read())
    check("site is back", get(anon, "/").status_code == 200)

    print("Conversion of per-page custom code")
    page = get(anon, "/legacy-styled").text
    m_css = re.search(r'<link rel="stylesheet" href="(/user/assets/css/\d+-imported-legacy-styled-css-\d+\.css\?v=[0-9a-f]{10})">', page)
    m_js = re.search(r'<script src="(/user/assets/js/\d+-imported-legacy-styled-js-\d+\.js\?v=[0-9a-f]{10})" defer></script>', page)
    check("the old inline code is now linked files", m_css is not None and m_js is not None, page[:600])
    check("no inline per-page style/script remains", "background:#eef" not in page and "__legacy" not in page)
    rc = get(anon, m_css.group(1)) if m_css else None
    rj = get(anon, m_js.group(1)) if m_js else None
    check("converted CSS is served as a static file with the original code", rc is not None and rc.status_code == 200 and "background:#eef" in rc.text and "css" in rc.headers.get("Content-Type", ""))
    check("converted JS is served as a static file", rj is not None and rj.status_code == 200 and "__legacy=1" in rj.text and "javascript" in rj.headers.get("Content-Type", ""))
    check("converted files exist on disk, world-readable", all(os.path.isfile(ROOT + p.split("?")[0]) and (os.stat(ROOT + p.split("?")[0]).st_mode & 0o777) == 0o644 for p in [m_css.group(1), m_js.group(1)]))
    log = open(ROOT + "/user/logs/app.log").read()
    check("unconvertible code is skipped and logged, not lost", "was not converted" in log and "PHP code is not allowed" in log)
    check("the Code screen lists the imported assets", "Imported: Legacy Styled (css)" in get(s, "/admin/code").text)
    page_edit = get(s, "/admin/content/%d" % int(re.search(r'/admin/content/(\d+)', get(s, "/admin/content?q=Legacy+Styled").text).group(1))).text
    check("editor shows them ticked; no custom CSS/JS textareas any more", page_edit.count("checked") >= 2 and 'name="custom_css"' not in page_edit and "Stylesheets and scripts for this item" in page_edit)
    check("search index was built during the update", int(os.popen("mysql -uroot -N cmse2e -e 'SELECT COUNT(*) FROM cms_search_index'").read().strip() or 0) >= 2)

    print("Navigation and access control")
    dash = get(s, "/admin").text
    check("nav has the new sections", all(("/admin/" + p) in dash for p in ["media", "tags", "code", "themes", "settings"]))
    for p in ["/admin/media", "/admin/tags", "/admin/code", "/admin/settings", "/admin/code/new", "/admin/media/1"]:
        check("requires sign-in: " + p, get(anon, p).status_code == 303)
    for p in ["/admin/media/upload", "/admin/code/new", "/admin/settings", "/admin/tags/1"]:
        check("POST without CSRF refused: " + p, s.post(BASE + p, data={"x": "1"}, allow_redirects=False).status_code == 403)
    check("unknown ids -> 404", get(s, "/admin/media/99999").status_code == 404 and get(s, "/admin/code/99999").status_code == 404 and get(s, "/admin/tags/99999").status_code in (404, 405))
    check("wrong methods -> 405", get(s, "/admin/media/upload").status_code == 405 and get(s, "/admin/settings/reindex").status_code == 405)

    print("Site settings")
    r = get(s, "/admin")
    check("dashboard asks for the site URL", "Set your public site URL" in r.text)
    r = get(s, "/admin/settings")
    check("settings screen suggests the detected URL", r.status_code == 200 and BASE in r.text and "Search index" in r.text)
    r = post(s, "/admin/settings", dict(site_name="Test Site", site_url="nope", site_lang="en", site_description="", site_logo_media_id="", site_social_media_id=""), token_from="/admin/settings")
    check("invalid URL rejected (422), message shown", r.status_code == 422 and "site URL must look like" in r.text)
    r = post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE + "/", site_lang="hi", site_description="A test site", site_logo_media_id="", site_social_media_id=""), token_from="/admin/settings")
    check("valid settings saved", r.status_code == 303)
    page = get(s, "/admin/settings").text
    check("URL normalised (no trailing slash), language and description kept", 'value="%s"' % BASE in page and 'value="hi"' in page and "A test site" in page)
    check("dashboard notice gone", "Set your public site URL" not in get(s, "/admin").text)
    check("public pages use the new language", '<html lang="hi">' in get(anon, "/legacy-styled").text)
    r = post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE, site_lang="hi", site_description="A test site", site_logo_media_id="999", site_social_media_id=""), token_from="/admin/settings")
    check("a logo that is not in the library is rejected", r.status_code == 422)

    print("Media library")
    r = upload(s, [("files[]", ("photo.png", fx("tiny.png"), "image/png")), ("files[]", ("doc.pdf", fx("tiny.pdf"), "application/pdf"))])
    check("upload redirects", r.status_code == 303)
    page = get(s, "/admin/media").text
    check("both files listed with a success message", "2 files uploaded" in page and "photo.png" in page and "doc.pdf" in page)
    ids = sorted(int(x) for x in set(re.findall(r'/admin/media/(\d+)"', page)))
    png_id = [i for i in ids if "photo.png" in get(s, "/admin/media/%d" % i).text][0]
    detail = get(s, "/admin/media/%d" % png_id).text
    url = re.search(r'<code class="user-select-all text-break">([^<]+)</code>', detail).group(1)
    check("detail shows address and an HTML snippet to paste", re.match(r"^/user/uploads/\d{4}/\d{2}/[0-9a-f]{20}\.png$", url) is not None and "&lt;img src=&quot;" in detail and "width=&quot;1&quot;" in detail and "loading=&quot;lazy&quot;" in detail)
    ru = get(requests.Session(), url)
    check("uploaded file is served publicly with the right type", ru.status_code == 200 and ru.headers["Content-Type"] == "image/png" and ru.content == fx("tiny.png"))
    check("stored under a random name, not the original", "photo" not in url and (os.stat(ROOT + url).st_mode & 0o777) == 0o644)
    r = upload(s, [("files[]", ("evil.png", b"<?php echo 'x'; ?>", "image/png"))])
    check("a PHP script named .png is refused", "not an allowed file type" in get(s, "/admin/media").text)
    r = upload(s, [("files[]", ("logo.svg", b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', "image/svg+xml"))])
    check("SVG is refused", "not an allowed file type" in get(s, "/admin/media").text)
    r = upload(s, [("files[]", ("big.png", fx("tiny.png") + b"0" * (3 * 1024 * 1024), "image/png"))])
    page = get(s, "/admin/media").text
    check("a file over the server limit gives a clear message", "larger than" in page)
    r = upload(s, [("files[]", ("huge.bin", b"0" * (10 * 1024 * 1024), "image/png"))])
    check("a request over post_max_size gets a clear 413 explanation", r.status_code == 413 and "larger than this server allows" in r.text, r.status_code)
    r = upload(s, [])
    check("uploading nothing is handled", r.status_code in (303, 400, 403))
    stray = ROOT + "/user/uploads/2026/10/stray.png"
    os.makedirs(os.path.dirname(stray), exist_ok=True)
    open(stray, "wb").write(b"x")
    check("upload folder has no executable scripts", not [f for _, _, fs in os.walk(ROOT + "/user/uploads") for f in fs if f.endswith((".php", ".phtml"))])
    r = post(s, "/admin/media/%d" % png_id, {"alt_text": "A tiny dot"}, token_from="/admin/media/%d" % png_id)
    check("alt text saved and used in the snippet", 'A tiny dot' in get(s, "/admin/media/%d" % png_id).text and 'alt="A tiny dot"' in get(s, "/admin/media/%d" % png_id).text.replace("&quot;", '"'))
    check("search by name", "photo.png" in get(s, "/admin/media?q=photo").text and "doc.pdf" not in get(s, "/admin/media?q=photo").text)
    post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE, site_lang="hi", site_description="A test site", site_logo_media_id=str(png_id), site_social_media_id=""), token_from="/admin/settings")
    header = get(anon, "/legacy-styled").text
    check("the logo appears in the public header", re.search(r'<img src="%s" alt="A tiny dot" height="32">' % re.escape(url), header) is not None)
    r = post(s, "/admin/media/%d/delete" % png_id, {}, token_from="/admin/media/%d" % png_id)
    check("a file used as the logo cannot be deleted", "still used as the site logo" in get(s, "/admin/media/%d" % png_id).text and os.path.isfile(ROOT + url))
    post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE, site_lang="hi", site_description="A test site", site_logo_media_id="", site_social_media_id=""), token_from="/admin/settings")
    r = post(s, "/admin/media/%d/delete" % png_id, {}, token_from="/admin/media/%d" % png_id)
    arch = [d for d in os.listdir(ROOT + "/user/backups/deleted") if d.startswith("media-%d-" % png_id)]
    check("deleting archives the file", r.status_code == 303 and not os.path.isfile(ROOT + url) and len(arch) == 1 and any(f.endswith(".png") for f in os.listdir(ROOT + "/user/backups/deleted/" + arch[0])))
    check("the deleted file's address is gone", get(anon, url).status_code == 404)
    page = get(s, "/admin/content/integrity").text
    check("integrity page reports the orphan upload and leaves it alone", "2026/10/stray.png" in page and os.path.isfile(stray))

    print("Code assets")
    r = post(s, "/admin/code/new", dict(kind="css", name="Wide tables", description="", load_order="50", code="<style>a{}</style>"), token_from="/admin/code/new?kind=css")
    check("pasted <style> wrapper rejected with an explanation", r.status_code == 422 and "without the" in r.text and "code" in r.text)
    r = post(s, "/admin/code/new", dict(kind="css", name="", description="", load_order="", code="a{}"), token_from="/admin/code/new?kind=css")
    check("empty name rejected", r.status_code == 422)
    r = post(s, "/admin/code/new", dict(kind="css", name="Wide tables", description="Makes tables full width", load_order="50", code="table.table{width:100%}\n"), token_from="/admin/code/new?kind=css")
    css_id = int(re.search(r"/admin/code/(\d+)$", r.headers["Location"]).group(1))
    check("stylesheet created", r.status_code == 303 and os.path.isfile(ROOT + "/user/assets/css/%d-wide-tables.css" % css_id))
    r = post(s, "/admin/code/new", dict(kind="css", name="Wide tables", description="", load_order="", code="a{}"), token_from="/admin/code/new?kind=css")
    check("duplicate name refused", r.status_code == 422 and "already has that name" in r.text)
    r = post(s, "/admin/code/new", dict(kind="js", name="Reading time", description="", load_order="10", code="window.__rt = 1;\n"), token_from="/admin/code/new?kind=js")
    js_id = int(re.search(r"/admin/code/(\d+)$", r.headers["Location"]).group(1))
    r = post(s, "/admin/code/new", dict(kind="css", name="Early reset", description="", load_order="1", code="p{margin:0}\n"), token_from="/admin/code/new?kind=css")
    early_id = int(re.search(r"/admin/code/(\d+)$", r.headers["Location"]).group(1))
    lst = get(s, "/admin/code").text
    check("list shows all three with kinds and usage", all(n in lst for n in ["Wide tables", "Reading time", "Early reset"]) and "0 item(s)" in lst)
    check("kind filter", "Reading time" not in get(s, "/admin/code?kind=css").text and "Reading time" in get(s, "/admin/code?kind=js").text)
    edit_page = get(s, "/admin/code/%d" % css_id).text
    check("edit form shows the code, address and no versions yet", "table.table{width:100%}" in edit_page and "/user/assets/css/%d-wide-tables.css?v=" % css_id in edit_page and "Earlier versions" in edit_page)
    r = post(s, "/admin/code/%d" % css_id, dict(kind="js", name="Wide tables", description="v2", load_order="50", code="table.table{width:95%}\n"), token_from="/admin/code/%d" % css_id)
    check("saving changes the file (kind cannot be changed)", r.status_code == 303 and "95%" in open(ROOT + "/user/assets/css/%d-wide-tables.css" % css_id).read() and not os.path.exists(ROOT + "/user/assets/js/%d-wide-tables.js" % css_id))
    page = get(s, "/admin/code/%d" % css_id).text
    ver = re.search(r'name="version" value="(\d{8}-\d{6}-[0-9a-f]{4}\.css)"', page)
    check("the old code is kept as a version", ver is not None and len(os.listdir(ROOT + "/user/backups/assets/%d" % css_id)) == 1)
    post(s, "/admin/code/%d/restore" % css_id, {"version": ver.group(1)}, token_from="/admin/code/%d" % css_id)
    check("restoring brings the old code back", "100%" in open(ROOT + "/user/assets/css/%d-wide-tables.css" % css_id).read() and len(os.listdir(ROOT + "/user/backups/assets/%d" % css_id)) == 2)
    r = post(s, "/admin/code/%d/restore" % css_id, {"version": "../../../../etc/passwd"}, token_from="/admin/code/%d" % css_id)
    check("a traversal 'version' is refused", "was not found" in get(s, "/admin/code/%d" % css_id).text)

    cid, _ = item(s, title="Page with linked code", body="<table class='table'><tr><td>x</td></tr></table>")
    form = get(s, "/admin/content/%d" % cid).text
    check("editor lists the assets as checkboxes with their kind", all(("asset%d" % i) in form for i in (css_id, js_id, early_id)))
    edit(s, cid, **{"asset_ids[]": [str(css_id), str(js_id), str(early_id)]})
    page = get(anon, "/page-with-linked-code").text
    links = re.findall(r'<link rel="stylesheet" href="(/user/assets/css/[^"]+)">', page)
    scripts = re.findall(r'<script src="(/user/assets/js/[^"]+)" defer></script>', page)
    check("linked CSS in the head in load order (lower first), JS deferred", len(links) == 2 and "early-reset" in links[0] and "wide-tables" in links[1] and len(scripts) == 1 and page.index(links[0]) < page.index("</head>") < page.index(scripts[0]))
    check("each linked file is fetchable and cacheable by URL version", all(get(anon, u).status_code == 200 for u in links + scripts) and all("?v=" in u for u in links + scripts))
    check("other pages do not load them", "wide-tables" not in get(anon, "/legacy-styled").text)
    page_before = links[1]
    post(s, "/admin/code/%d" % css_id, dict(kind="css", name="Wide tables", description="v3", load_order="50", code="table.table{width:50%}\n"), token_from="/admin/code/%d" % css_id)
    links2 = re.findall(r'<link rel="stylesheet" href="(/user/assets/css/[^"]+wide-tables[^"]+)">', get(anon, "/page-with-linked-code").text)
    check("changing the code changes the ?v= so browsers re-download it", len(links2) == 1 and links2[0] != page_before and links2[0].split("?")[0] == page_before.split("?")[0])
    r = edit(s, cid, **{"asset_ids[]": ["999999"]})
    check("linking a non-existent asset is rejected", r.status_code == 422)
    used = get(s, "/admin/code/%d" % css_id).text
    check("the asset page lists the pages using it", "Page with linked code" in used)
    edit(s, cid, **{"asset_ids[]": [str(early_id)]})
    page = get(anon, "/page-with-linked-code").text
    check("unticking removes the link", "wide-tables" not in page and "early-reset" in page and "reading-time" not in page)
    post(s, "/admin/code/%d/delete" % early_id, {}, token_from="/admin/code/%d" % early_id)
    arch = [d for d in os.listdir(ROOT + "/user/backups/deleted") if d.startswith("asset-%d-" % early_id)]
    check("deleting an asset unlinks it and archives the file", "early-reset" not in get(anon, "/page-with-linked-code").text and len(arch) == 1 and not os.path.exists(ROOT + "/user/assets/css/%d-early-reset.css" % early_id))
    os.remove(ROOT + "/user/assets/js/%d-reading-time.js" % js_id)
    check("a missing asset file is reported on the Code screen", "files missing for Reading time" in get(s, "/admin/code").text)
    check("the CSP of public pages is unchanged (nonce still available to themes)", "script-src 'self' https://cdn.jsdelivr.net 'nonce-" in get(anon, "/page-with-linked-code").headers["Content-Security-Policy"])

    print("Tags and SEO fields in the editor")
    tid, _ = item(s, title="Tag test", tags="Law, Constitution notes, अनुच्छेद 21")
    form = get(s, "/admin/content/%d" % tid).text
    check("tags saved and shown back in the editor", 'value="Law, Constitution notes, अनुच्छेद 21"' in form or 'value="Constitution notes, Law, अनुच्छेद 21"' in form)
    page = get(s, "/admin/tags").text
    check("Tags screen lists them with usage", all(t in page for t in ["Law", "Constitution notes", "अनुच्छेद 21"]) and "/tag/law" in page)
    tag_id = int(re.search(r'/admin/tags/(\d+)/delete', page).group(1))
    law_id = int(os.popen("mysql -uroot -N cmse2e -e \"SELECT id FROM cms_tags WHERE name='Law'\"").read().strip())
    post(s, "/admin/tags/%d" % law_id, {"name": "Statute law"}, token_from="/admin/tags")
    check("renaming a tag keeps its address", "Statute law" in get(s, "/admin/tags").text and "/tag/law" in get(s, "/admin/tags").text)
    post(s, "/admin/tags/%d" % law_id, {"name": "Constitution notes"}, token_from="/admin/tags")
    check("duplicate tag name refused", "Another tag already has that name" in get(s, "/admin/tags").text)
    post(s, "/admin/tags/%d/delete" % law_id, {}, token_from="/admin/tags")
    after = get(s, "/admin/tags").text
    check("deleting a tag removes it from items; items stay", "/tag/law" not in after and "deleted from every item" in after and "Constitution notes" in after and get(s, "/admin/content/%d" % tid).status_code == 200)
    r = edit(s, tid, tags=",".join(str(i) for i in range(20)))
    check("more than 15 tags rejected", r.status_code == 422 and "at most 15 tags" in r.text)
    r = edit(s, tid, seo_title="SEO Title Here", og_image_media_id="999999")
    check("a social image that is not an image from the library is rejected", r.status_code == 422)
    upload(s, [("files[]", ("social.png", fx("tiny.png"), "image/png"))])
    mid = int(re.search(r'/admin/media/(\d+)"', get(s, "/admin/media?q=social").text).group(1))
    r = edit(s, tid, seo_title="SEO Title Here", og_image_media_id=str(mid), tags="Constitution notes")
    form = get(s, "/admin/content/%d" % tid).text
    check("SEO title and social image saved", r.status_code == 303 and 'value="SEO Title Here"' in form and re.search(r'name="og_image_media_id" value="%d"' % mid, form) is not None and 'data-ac-label="social.png"' in form)
    r = post(s, "/admin/media/%d/delete" % mid, {}, token_from="/admin/media/%d" % mid)
    check("an image used as a social image cannot be deleted", "social image of" in get(s, "/admin/media/%d" % mid).text)

    print("Slug rename is recorded; settings reindex tool")
    rid, _ = item(s, title="Rename me")
    edit(s, rid, slug="renamed-page")
    row = os.popen("mysql -uroot -N cmse2e -e \"SELECT old_path FROM cms_content_redirects WHERE content_id=%d\"" % rid).read().strip()
    check("the old slug is stored as a redirect", row == "rename-me")
    check("the new address works", get(anon, "/renamed-page").status_code == 200)
    r = post(s, "/admin/settings/reindex", {}, token_from="/admin/settings")
    check("search index can be rebuilt from the settings screen", r.status_code == 303 and "Search index rebuilt" in get(s, "/admin/settings").text)
    n_idx = int(os.popen("mysql -uroot -N cmse2e -e 'SELECT COUNT(*) FROM cms_search_index'").read().strip())
    n_content = int(os.popen("mysql -uroot -N cmse2e -e 'SELECT COUNT(*) FROM cms_content WHERE deleted_at IS NULL'").read().strip())
    check("index covers every non-trashed item", n_idx == n_content, (n_idx, n_content))
    post(s, "/admin/content/%d/trash" % rid, {}, token_from="/admin/content")
    post(s, "/admin/content/%d/purge" % rid, {}, token_from="/admin/content?status=trash")
    check("purged item leaves no index or redirect rows", os.popen("mysql -uroot -N cmse2e -e 'SELECT (SELECT COUNT(*) FROM cms_search_index WHERE content_id=%d)+(SELECT COUNT(*) FROM cms_content_redirects WHERE content_id=%d)'" % (rid, rid)).read().strip() == "0")

    print("Housekeeping")
    perr = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
    check("no PHP warnings or errors in the PHP error log", not perr, perr)
    log = open(ROOT + "/user/logs/app.log").read()
    check("no secrets in the application log", PW not in log and "cmspass" not in log)

if __name__ == "__main__":
    {"partA": partA, "partB": partB}[sys.argv[1]]()
    print("\n%d passed, %d failed" % (res["pass"], res["fail"]))
    sys.exit(1 if res["fail"] else 0)
