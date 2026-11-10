"""Phase 4B end-to-end: install the Phase 4A release, build a book hierarchy and upload a photo (4A has flat child addresses and no
thumbnails), upgrade to the current code, then test hierarchical addresses, old-address redirects and thumbnails.
Driven by tests/e2e_phase4b.sh."""
import os, re, sys, html, json
import requests

os.environ.setdefault("CMS_E2E_ROOT", "/tmp/e2e4b")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_upgrade import BASE, ROOT, PW, VERSION, check, res, get, post, csrf, login  # noqa: E402

FX = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures")
def fx(name): return open(os.path.join(FX, name), "rb").read()
def sql(q): return os.popen("mysql -uroot -N cmse2e -e \"%s\"" % q).read().strip()

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
        return html.unescape(m.group(1)) if m else ""
    def sel(name):
        if name == "parent_id":
            m = re.search(r'name="parent_id" value="([^"]*)"', page)
            return m.group(1) if m else ""
        block = re.search(r'<select[^>]*name="%s"[^>]*>(.*?)</select>' % name, page, re.S)
        m = re.search(r'<option value="([^"]*)" selected', block.group(1)) if block else None
        return m.group(1) if m else ""
    body = html.unescape(re.search(r'<textarea[^>]*name="body"[^>]*>(.*?)</textarea>', page, re.S).group(1))
    data = dict(title=val("title"), type=sel("type") or "article", status=sel("status") or "published", visibility=sel("visibility") or "public", slug=val("slug"),
                parent_id=sel("parent_id"), body=body, excerpt="", meta_description="", seo_title="", og_image_media_id="", tags="", view_password="")
    data.update(over)
    return post(s, "/admin/content/%d" % cid, data, token_from="/admin/content/%d" % cid)

def upload(s, files):
    tok = csrf(get(s, "/admin/media").text)
    return s.post(BASE + "/admin/media/upload", data={"_csrf": tok}, files=files, allow_redirects=False)

def dims(path):
    out = os.popen("php -r '$i = @getimagesize($argv[1]); echo $i ? $i[0] . \"x\" . $i[1] : \"\";' %s" % path).read().strip()
    return [int(x) for x in out.split("x")] if out else None

def partA():
    print("Part A: Phase 4A baseline (flat child addresses, no thumbnails)")
    s = requests.Session()
    good = dict(db_host="127.0.0.1", db_port="3306", db_name="cmse2e", db_user="cms", db_pass="cmspass", prefix="cms_", site_name="Test Site",
                admin_username="prateek", admin_email="a@example.com", admin_password=PW, admin_password2=PW)
    check("Phase 4A installs", "Installation complete" in post(s, "/install.php", good, token_from="/install.php").text)
    a = login()
    root, _ = item(a, title="Law Book", type="book", body="<p>root</p>")
    part, _ = item(a, title="Part One", type="book", parent_id=str(root), body="<p>part one</p>")
    art, _ = item(a, title="Article 1", type="book", parent_id=str(part), body="<p>article one</p>")
    part2, _ = item(a, title="Part Two", type="book", parent_id=str(root), body="<p>part two</p>")
    check("4A serves children at their flat slug", get(requests.Session(), "/part-one").status_code == 200 and get(requests.Session(), "/article-1").status_code == 200)
    old, _ = item(a, title="Old Name", body="<p>renamed later</p>")
    r = post(a, "/admin/content/%d" % old, dict(title="Old Name", type="article", status="published", visibility="public", slug="new-name", parent_id="", body="<p>renamed later</p>", excerpt="", meta_description="", seo_title="", og_image_media_id="", tags="", view_password=""), token_from="/admin/content/%d" % old)
    check("a 4A slug rename is recorded", sql("SELECT old_slug FROM cms_content_redirects") == "old-name")
    hid, _ = item(a, title="भारतीय संविधान", type="book", body="<p>हिंदी</p>")
    hic, _ = item(a, title="अनुच्छेद 21", type="book", parent_id=str(hid), body="<p>जीवन</p>")
    r = upload(a, [("files[]", ("photo.jpg", fx("photo-large.jpg"), "image/jpeg")), ("files[]", ("dot.png", fx("tiny.png"), "image/png"))])
    check("4A uploads work (no thumbnails there)", r.status_code == 303 and not os.path.exists(ROOT + "/core/engine/Thumbnailer.php"))

def partB():
    anon = requests.Session()
    print("Upgrade 4A -> current")
    check("site shows 'updating' until the update is applied", get(anon, "/").status_code == 503)
    s = login()
    r = get(s, "/admin/update")
    check("update screen lists the new migrations", "0009_content_paths" in r.text and "0010_media_thumbnails" in r.text)
    r = post(s, "/admin/update/apply", {}, token_from="/admin/update")
    check("update applies cleanly", r.status_code == 303 and r.headers["Location"].endswith("/admin"), r.text[:300])
    check("version recorded", VERSION in open(ROOT + "/user/config/app.php").read())

    print("Hierarchical addresses after the upgrade")
    paths = dict(r.split("\t") for r in sql("SELECT title, path FROM cms_content").split("\n"))
    check("existing items got addresses from their parents", paths["Law Book"] == "law-book" and paths["Part One"] == "law-book/part-one" and paths["Article 1"] == "law-book/part-one/article-1" and paths["Old Name"] == "new-name")
    for p in ["/law-book", "/law-book/part-one", "/law-book/part-one/article-1", "/law-book/part-two"]:
        check("new address works: " + p, get(anon, p).status_code == 200)
    for old, new in [("/part-one", "/law-book/part-one"), ("/article-1", "/law-book/part-one/article-1"), ("/part-two", "/law-book/part-two"), ("/old-name", "/new-name")]:
        r = get(anon, old)
        check("old address %s redirects (301) to %s" % (old, new), r.status_code == 301 and r.headers["Location"].endswith(new), (r.status_code, r.headers.get("Location")))
    r = get(anon, "/LAW-BOOK/Part-One")
    check("different case redirects (301) to the canonical address", r.status_code == 301 and r.headers["Location"].endswith("/law-book/part-one"))
    page = get(anon, "/law-book/part-one/article-1").text
    check("breadcrumb links use full addresses", 'href="/law-book"' in page and 'href="/law-book/part-one"' in page)
    page = get(anon, "/law-book").text
    check("a book page lists children at their full addresses", 'href="/law-book/part-one"' in page and 'href="/law-book/part-two"' in page and 'href="/part-one"' not in page)
    page = get(anon, "/law-book/part-one").text
    check("next/previous and child links use full addresses", 'href="/law-book/part-two"' in page and 'href="/law-book/part-one/article-1"' in page)
    for p in ["/law-book/nope", "/law-book/part-one/article-1/extra", "/nope/part-one", "/" + "/".join("s%d" % i for i in range(13))]:
        check("not found: " + p[:40], get(anon, p).status_code == 404)
    check("the child of an old flat address cannot be reached through a wrong parent", get(anon, "/law-book/part-one/part-two").status_code == 404)
    check("the site's home page and static assets still work", get(anon, "/").status_code == 200 and get(anon, "/assets/base/css/theme.css").status_code == 200)

    print("Unicode hierarchy")
    hp = "/%E0%A4%AD%E0%A4%BE%E0%A4%B0%E0%A4%A4%E0%A5%80%E0%A4%AF-%E0%A4%B8%E0%A4%82%E0%A4%B5%E0%A4%BF%E0%A4%A7%E0%A4%BE%E0%A4%A8"
    r = get(anon, hp + "/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6-21")
    check("a Hindi parent/child address works", r.status_code == 200 and "जीवन" in r.text)
    check("the Hindi parent links to its child with percent-encoded segments", hp + "/%E0%A4%85" in get(anon, hp).text)
    r = get(anon, "/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6-21")
    check("the old flat Hindi address redirects", r.status_code == 301 and r.headers["Location"].startswith(hp))

    print("Creating and editing with addresses")
    row = lambda title: int(sql("SELECT id FROM cms_content WHERE title='%s' ORDER BY id DESC LIMIT 1" % title))
    book2, _ = item(s, title="Other Book", type="book", body="<p>other</p>")
    root = row("Law Book")
    i1, _ = item(s, title="Introduction", type="book", parent_id=str(root), body="<p>intro one</p>")
    i2, _ = item(s, title="Introduction", type="book", parent_id=str(book2), body="<p>intro two</p>")
    i3, _ = item(s, title="Introduction", type="book", parent_id=str(root), body="<p>intro three</p>")
    check("same slug under different parents; -2 under the same parent", all(get(anon, p).status_code == 200 for p in ["/law-book/introduction", "/other-book/introduction", "/law-book/introduction-2"]) and "intro two" in get(anon, "/other-book/introduction").text)
    r = post(s, "/admin/content/new", dict(title="Dup", type="book", status="draft", slug="part-one", parent_id=str(root), body="", visibility="public"), token_from="/admin/content/new?type=book")
    check("an explicit slug already used under the same parent is rejected with the full address", r.status_code == 422 and "/law-book/part-one" in r.text)
    top, _ = item(s, title="Introduction", type="page", body="<p>top intro</p>")
    check("a top-level page may share the slug with children", get(anon, "/introduction").status_code == 200 and "top intro" in get(anon, "/introduction").text)
    adm, _ = item(s, title="Admin", type="book", parent_id=str(root), body="<p>child named admin</p>")
    check("a child may be called 'admin' (reserved only at the top level)", get(anon, "/law-book/admin").status_code == 200 and get(anon, "/admin").status_code == 303)
    form = get(s, "/admin/content/%d" % i1).text
    check("the editor shows the current address", "Current address:" in form and "/law-book/introduction" in form)
    check("the list shows addresses", "/law-book/part-one/article-1" in get(s, "/admin/content?q=Article").text)
    check("'View live' uses the full address", 'href="/law-book/part-one/article-1"' in get(s, "/admin/content/%d" % row("Article 1")).text)

    print("Renaming a parent and moving pages")
    edit(s, root, slug="law-handbook")
    for p in ["/law-handbook", "/law-handbook/part-one", "/law-handbook/part-one/article-1"]:
        check("renamed subtree works: " + p, get(anon, p).status_code == 200)
    for old, new in [("/law-book", "/law-handbook"), ("/law-book/part-one", "/law-handbook/part-one"), ("/law-book/part-one/article-1", "/law-handbook/part-one/article-1"), ("/part-one", "/law-handbook/part-one")]:
        r = get(anon, old)
        check("old address %s still redirects, straight to %s" % (old, new), r.status_code == 301 and r.headers["Location"].endswith(new))
    part = row("Part One")
    edit(s, part, parent_id=str(book2))
    check("moving a page re-addresses it and its children", get(anon, "/other-book/part-one/article-1").status_code == 200 and get(anon, "/law-handbook/part-one").status_code == 301)
    check("the old addresses of the moved page lead to the new one", get(anon, "/law-handbook/part-one/article-1").headers["Location"].endswith("/other-book/part-one/article-1") and get(anon, "/part-one").headers["Location"].endswith("/other-book/part-one"))
    r = edit(s, i1, parent_id=str(book2))
    check("moving into a parent that already has that slug is refused", r.status_code == 422 and "already used" in r.text)
    r = edit(s, root, parent_id=str(i1))
    check("a book cannot move under its own descendant", r.status_code == 422 and "under itself" in r.text)
    post(s, "/admin/content/%d/trash" % part, {}, token_from="/admin/content")
    check("trashing a page that still has children is refused", "child pages" in get(s, "/admin/content").text and get(anon, "/other-book/part-one").status_code == 200)
    art = row("Article 1")
    post(s, "/admin/content/%d/trash" % art, {}, token_from="/admin/content")
    check("a trashed page's address and old addresses are 404", get(anon, "/other-book/part-one/article-1").status_code == 404 and get(anon, "/law-book/part-one/article-1").status_code == 404)
    post(s, "/admin/content/%d/restore" % art, {}, token_from="/admin/content?status=trash")
    check("restoring brings its address back", get(anon, "/other-book/part-one/article-1").status_code == 200)
    nm = get(anon, "/old-name")
    check("older redirects from before the upgrade still work after all this", nm.status_code == 301 and nm.headers["Location"].endswith("/new-name"))

    print("Live addresses beat old redirects; private pages")
    n, _ = item(s, title="Old book name reused", type="page", slug="law-book", body="<p>a new page took the old address</p>")
    check("a new page may take an address that only exists as a redirect", get(anon, "/law-book").status_code == 200 and "took the old address" in get(anon, "/law-book").text)
    priv, _ = item(s, title="Secret Part", type="book", parent_id=str(book2), visibility="private", view_password="open-sesame-1", body="<p>TOP SECRET</p>")
    v = requests.Session()
    r = get(v, "/other-book/secret-part")
    check("a private child page asks for the password at its full address", r.status_code == 200 and "This page is private" in r.text and "TOP SECRET" not in r.text and 'action="/other-book/secret-part"' in r.text)
    r = post(v, "/other-book/secret-part", {"password": "open-sesame-1"}, token_from="/other-book/secret-part")
    check("after unlocking, the visitor returns to the same full address", r.status_code == 303 and r.headers["Location"].endswith("/other-book/secret-part") and "TOP SECRET" in get(v, "/other-book/secret-part").text)
    edit(s, priv, slug="secret-renamed", visibility="private", view_password="")
    check("renaming a private page does not leak it through the redirect", get(anon, "/other-book/secret-part").status_code == 404)
    check("the listing of its book hides it", "Secret" not in get(anon, "/other-book").text)

    print("Thumbnails")
    page = get(s, "/admin/media").text
    check("older images without thumbnails are announced with a Generate button", "have no thumbnail yet" in page and "Generate thumbnails" in page and "/thumbs/" not in page)
    r = post(s, "/admin/media/thumbnails", {}, token_from="/admin/media")
    page = get(s, "/admin/media").text
    check("generating thumbnails reports what was created", "thumbnail(s) created" in page and "have no thumbnail yet" not in page)
    thumbs = re.findall(r'src="(/user/uploads/\d{4}/\d{2}/thumbs/[0-9a-f]{20}\.jpg)"', page)
    check("the grid now uses a thumbnail for the large photo and the original for the tiny one", len(thumbs) == 1 and re.search(r'src="/user/uploads/\d{4}/\d{2}/[0-9a-f]{20}\.png"', page) is not None, thumbs)
    t = get(anon, thumbs[0])
    big = get(anon, re.search(r'/user/uploads/\d{4}/\d{2}/[0-9a-f]{20}\.jpg', get(s, "/admin/media/%d" % int(sql("SELECT id FROM cms_media WHERE original_name='photo.jpg'"))).text).group(0))
    check("the thumbnail is a real, much smaller JPEG served statically", t.status_code == 200 and t.headers["Content-Type"] == "image/jpeg" and len(t.content) < len(big.content) / 2, (len(t.content), len(big.content)))
    check("thumbnail dimensions: 400 x 267", dims(ROOT + thumbs[0]) == [400, 267], dims(ROOT + thumbs[0]))
    check("thumbnail files are world-readable and there are no scripts in uploads", (os.stat(ROOT + thumbs[0]).st_mode & 0o777) == 0o644 and not [f for _, _, fs in os.walk(ROOT + "/user/uploads") for f in fs if f.endswith((".php", ".phtml"))])
    r = upload(s, [("files[]", ("round.png", fx("round-alpha.png"), "image/png"))])
    mid = int(sql("SELECT id FROM cms_media WHERE original_name='round.png'"))
    detail = get(s, "/admin/media/%d" % mid).text
    row = sql("SELECT thumb_status, thumb_path, thumb_width, thumb_height FROM cms_media WHERE id=%d" % mid).split("\t")
    check("a new upload gets its thumbnail immediately (PNG keeps transparency)", row[0] == "ok" and row[1].endswith(".png") and (row[2], row[3]) == ("400", "400") and "400 &times; 400 px" in detail)
    tp = ROOT + "/user/uploads/" + row[1]
    os.remove(tp)
    check("integrity reports the missing thumbnail", "Thumbnails that are recorded but missing" in get(s, "/admin/content/integrity").text)
    post(s, "/admin/media/%d/thumbnail" % mid, {}, token_from="/admin/media/%d" % mid)
    check("'Create again' rebuilds it", os.path.isfile(tp) and "Thumbnail created" in get(s, "/admin/media/%d" % mid).text)
    post(s, "/admin/media/%d/delete" % mid, {}, token_from="/admin/media/%d" % mid)
    arch = [d for d in os.listdir(ROOT + "/user/backups/deleted") if d.startswith("media-%d-" % mid)]
    check("deleting archives the thumbnail with the original", len(arch) == 1 and any(f.startswith("thumb-") for f in os.listdir(ROOT + "/user/backups/deleted/" + arch[0])) and not os.path.exists(tp))
    check("thumbnail actions require sign-in and CSRF", get(anon, "/admin/media/1/thumbnail").status_code in (303, 405) and s.post(BASE + "/admin/media/thumbnails", data={}, allow_redirects=False).status_code == 403)
    check("search by name still works with thumbnails present", "photo.jpg" in get(s, "/admin/media?q=photo").text)


    print("Autocomplete fields (parent and media pickers)")
    check("admin pages allow requests to this site only (connect-src 'self') and still no inline script", "connect-src 'self'" in get(s, "/admin").headers["Content-Security-Policy"] and "script-src 'self';" in get(s, "/admin").headers["Content-Security-Policy"])
    dash = get(s, "/admin").text
    check("every admin page loads the stylesheet and the autocomplete script", "/admin/assets/admin.css?v=" in dash and "/admin/assets/autocomplete.js?v=" in dash)
    for name, ctype in [("admin.css", "text/css"), ("autocomplete.js", "javascript"), ("admin.js", "javascript")]:
        r = get(anon, "/admin/assets/" + name)
        check("asset served: " + name, r.status_code == 200 and ctype in r.headers["Content-Type"] and r.headers.get("X-Content-Type-Options") == "nosniff" and len(r.text) > 100)
    check("autocomplete.js builds results with textContent only", "innerHTML" not in get(anon, "/admin/assets/autocomplete.js").text)
    for bad in ["/admin/assets/nope.js", "/admin/assets/..%2f..%2fcore%2fVERSION", "/admin/assets/%2e%2e/%2e%2e/core/engine/Config.php", "/admin/assets/", "/admin/assets/admin.php"]:
        rr = get(anon, bad)
        check("asset whitelist refuses " + bad, rr.status_code in (403, 404) and "<?php" not in rr.text and rr.text.strip() != VERSION)

    form = get(s, "/admin/content/%d" % i1).text
    check("the editor has no parent select box any more", not re.search(r'<select[^>]*name="parent_id"', form) and not re.search(r'<select[^>]*name="og_image_media_id"', form))
    check("the parent field is an autocomplete bound to the books endpoint, excluding the page itself", 'data-ac-url="/admin/lookup/content-parent"' in form and ('data-ac-exclude="%d"' % i1) in form and 'data-ac-value' in form and 'type="number"' in form)
    check("the current parent is shown by name and the field holds its id", 'data-ac-label="Law Book"' in form and re.search(r'name="parent_id" value="%d"' % root, form) is not None)
    check("a new item's form has no exclude", "data-ac-exclude" not in get(s, "/admin/content/new?type=book").text and 'data-ac-url="/admin/lookup/content-parent"' in get(s, "/admin/content/new?type=book").text)
    check("the social-image field uses the media endpoint", 'data-ac-url="/admin/lookup/media-image"' in form)

    lk = lambda path, **q: get(s, path, params=q)
    check("lookups need a signed-in administrator", get(anon, "/admin/lookup/content-parent").status_code == 303 and get(anon, "/admin/lookup/media-image").status_code == 303)
    r = lk("/admin/lookup/content-parent", q="introduction")
    j = r.json()
    check("books endpoint: JSON, never cached, only books, with address hints", r.status_code == 200 and r.headers["Content-Type"].startswith("application/json") and "no-store" in r.headers["Cache-Control"] and len(j["items"]) >= 3 and all(i["hint"].startswith("/") and "introduction" in i["hint"] for i in j["items"]))
    check("pages and articles are never offered as parents (the top-level page /introduction is absent)", "/introduction" not in [i["hint"] for i in j["items"]])
    r = lk("/admin/lookup/content-parent", q="part", exclude=str(part))
    check("the exclude parameter removes the item and its children", all(i["id"] != part and i["id"] != art for i in r.json()["items"]))
    r = lk("/admin/lookup/content-parent", q="Secret")
    check("private books can still be chosen as parents by the administrator", any("Secret Part" in i["label"] for i in r.json()["items"]))
    evil, _ = item(s, title='</script><img src=x onerror=alert(1)>', type="book", body="<p>x</p>")
    r = lk("/admin/lookup/content-parent", q="onerror")
    check("hostile titles come back as safe JSON text", "<" not in r.text and ">" not in r.text and r.json()["items"][0]["label"] == '</script><img src=x onerror=alert(1)>')
    check("odd parameters are harmless", lk("/admin/lookup/content-parent", q="x" * 3000, exclude="abc").status_code == 200 and lk("/admin/lookup/content-parent", exclude="1;DROP").status_code == 200 and get(s, "/admin/lookup/content-parent?q[]=1").status_code == 200)
    check("unknown sources 404, other methods refused", get(s, "/admin/lookup/other").status_code == 404 and post(s, "/admin/lookup/content-parent", {}, token_from="/admin").status_code in (405, 404))
    r = lk("/admin/lookup/media-image", q="photo")
    check("media endpoint: images with thumbnail URLs and sizes", r.status_code == 200 and len(r.json()["items"]) == 1 and "thumbs/" in r.json()["items"][0]["thumb"] and "1200 × 800 px" in r.json()["items"][0]["hint"])
    allimgs = lk("/admin/lookup/media-image", q="").json()["items"]
    check("media endpoint lists images only, newest first", len(allimgs) >= 2 and all(i["label"].endswith((".png", ".jpg")) for i in allimgs) and [i["id"] for i in allimgs] == sorted([i["id"] for i in allimgs], reverse=True))

    r = edit(s, i1, parent_id=str(book2))
    check("saving with the id from the autocomplete field still validates on the server (duplicate under that parent refused)", r.status_code == 422)
    r = edit(s, i1, parent_id="99999999")
    check("a non-existent parent id is rejected by the server", r.status_code == 422 and "valid parent" in r.text)
    dot = int(sql("SELECT id FROM cms_media WHERE original_name='dot.png'"))
    post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE, site_lang="en", site_description="", site_logo_media_id=str(dot), site_social_media_id=""), token_from="/admin/settings")
    sform = get(s, "/admin/settings").text
    check("the settings page uses media autocompletes for the logo and the social image, showing the chosen logo by name", not re.search(r'<select[^>]*name="site_(logo|social)_media_id"', sform) and sform.count('data-ac-url="/admin/lookup/media-image"') == 2 and 'data-ac-label="dot.png"' in sform and re.search(r'name="site_logo_media_id" value="%d"' % dot, sform) is not None)
    r = post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE, site_lang="en", site_description="", site_logo_media_id="999999", site_social_media_id=""), token_from="/admin/settings")
    check("a logo id that does not exist is rejected by the server", r.status_code == 422)
    post(s, "/admin/settings", dict(site_name="Test Site", site_url=BASE, site_lang="en", site_description="", site_logo_media_id="", site_social_media_id=""), token_from="/admin/settings")

    print("Housekeeping")
    perr = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
    check("no PHP warnings or errors in the PHP error log", not perr, perr)

if __name__ == "__main__":
    {"partA": partA, "partB": partB}[sys.argv[1]]()
    print("\n%d passed, %d failed" % (res["pass"], res["fail"]))
    sys.exit(1 if res["fail"] else 0)
