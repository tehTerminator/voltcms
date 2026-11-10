"""Phase 4C end-to-end: install the 4B1 release, seed content (tags, drafts, private, noindex, trash, hierarchy, Hindi, images),
upgrade to the current code, then test SEO tags, robots.txt, sitemap, RSS feed, tag pages and search. Driven by tests/e2e_phase4c.sh."""
import os, re, sys, html
import xml.etree.ElementTree as ET
import requests

os.environ.setdefault("CMS_E2E_ROOT", "/tmp/e2e4c")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_upgrade import BASE, ROOT, PW, VERSION, check, res, get, post, csrf, login  # noqa: E402
from e2e_phase4b import item, edit, upload, sql, fx  # noqa: E402

SM = "{http://www.sitemaps.org/schemas/sitemap/0.9}"

def meta(page, attr, name):
    m = re.search(r'<meta %s="%s" content="([^"]*)">' % (attr, re.escape(name)), page)
    return html.unescape(m.group(1)) if m else None

def link(page, rel):
    m = re.search(r'<link rel="%s"[^>]*href="([^"]*)"' % rel, page)
    return html.unescape(m.group(1)) if m else None

def settings(s, **over):
    data = dict(site_name="Test Site", site_url=BASE, site_lang="en", site_description="A site about law and notes", site_logo_media_id="", site_social_media_id="")
    data.update(over)
    return post(s, "/admin/settings", data, token_from="/admin/settings")

def partA():
    print("Part A: 4B1 baseline with seeded content")
    s = requests.Session()
    good = dict(db_host="127.0.0.1", db_port="3306", db_name="cmse2e", db_user="cms", db_pass="cmspass", prefix="cms_", site_name="Test Site",
                admin_username="prateek", admin_email="a@example.com", admin_password=PW, admin_password2=PW)
    check("4B1 installs", "Installation complete" in post(s, "/install.php", good, token_from="/install.php").text)
    a = login()
    upload(a, [("files[]", ("photo.jpg", fx("photo-large.jpg"), "image/jpeg")), ("files[]", ("social.png", fx("round-alpha.png"), "image/png"))])
    photo = int(sql("SELECT id FROM cms_media WHERE original_name='photo.jpg'"))
    social = int(sql("SELECT id FROM cms_media WHERE original_name='social.png'"))
    settings(a, site_social_media_id=str(social))
    item(a, title="Alpha Law", tags="law, notes", excerpt="Alpha excerpt text", body="<p>alpha lawword body</p>")
    for i in range(1, 14):
        item(a, title="Law Filler %02d" % i, tags="law", body="<p>lawword filler %d</p>" % i)
    item(a, title="Seo Titled", tags="notes", seo_title="Custom SEO Title", meta_description="Custom meta description", og_image_media_id=str(photo), body="<p>seo body</p>")
    item(a, title="No Description", body="", excerpt="")
    item(a, title="About Us", type="page", tags="notes", body="<p>about us page</p>")
    item(a, title="Quiet Article", tags="law", noindex="1", body="<p>quiet lawword</p>")
    item(a, title="Draft Secret", tags="secret-tag", status="draft", body="<p>draftword</p>")
    pid, _ = item(a, title="Private Papers", type="page", tags="only-private", visibility="private", view_password="open-sesame-1", body="<p>privateword content</p>")
    tid, _ = item(a, title="Trashed One", tags="law", body="<p>trashedword</p>")
    post(a, "/admin/content/%d/trash" % tid, {}, token_from="/admin/content")
    root, _ = item(a, title="Law Book", type="book", body="<p>book root</p>")
    item(a, title="Part One", type="book", parent_id=str(root), tags="law", body="<p>part one lawword</p>")
    hr, _ = item(a, title="भारतीय संविधान", type="book", body="<p>हिंदी जड़</p>")
    item(a, title="अनुच्छेद 21", type="book", parent_id=str(hr), tags="अनुच्छेद", body="<p>जीवन और स्वतंत्रता</p>")
    item(a, title='"><script>alert(1)</script> Evil', tags="evil", body="<p>evilword</p>")
    check("4B1 has no sitemap, feed or tag pages yet", get(requests.Session(), "/sitemap.xml").status_code == 404 and get(requests.Session(), "/feed.xml").status_code == 404)

def partB():
    anon = requests.Session()
    print("Upgrade 4B1 -> current")
    s = login()
    r = get(s, "/admin/update")
    check("update screen: version only, no database changes", "No database changes are needed" in r.text)
    r = post(s, "/admin/update/apply", {}, token_from="/admin/update")
    check("update applies", r.status_code == 303 and VERSION in open(ROOT + "/user/config/app.php").read())

    print("Page head: canonical, social tags, robots")
    pg = get(anon, "/alpha-law").text
    check("article: canonical, og:type article, og:title, og:description (excerpt), og:url", link(pg, "canonical") == BASE + "/alpha-law" and meta(pg, "property", "og:type") == "article" and meta(pg, "property", "og:title") == "Alpha Law" and meta(pg, "property", "og:description") == "Alpha excerpt text" and meta(pg, "property", "og:url") == BASE + "/alpha-law")
    check("article: published/modified times, site name, default social image, large twitter card", re.match(r"^\d{4}-\d{2}-\d{2}T[\d:]+\+00:00$", meta(pg, "property", "article:published_time") or "") is not None and meta(pg, "property", "og:site_name") == "Test Site" and re.match(re.escape(BASE) + r"/user/uploads/\d{4}/\d{2}/[0-9a-f]{20}\.png$", meta(pg, "property", "og:image") or "") and meta(pg, "name", "twitter:card") == "summary_large_image" and meta(pg, "name", "twitter:title") == "Alpha Law")
    check("description meta and the feed link are present; no robots tag on an ordinary page", meta(pg, "name", "description") == "Alpha excerpt text" and link(pg, "alternate") == BASE + "/feed.xml" and meta(pg, "name", "robots") is None)
    sp = get(anon, "/seo-titled").text
    check("SEO title replaces the title in <title> and og:title; own meta description and own image win", "<title>Custom SEO Title | Test Site</title>" in sp and meta(sp, "property", "og:title") == "Custom SEO Title" and meta(sp, "name", "description") == "Custom meta description" and "/user/uploads/" in meta(sp, "property", "og:image") and meta(sp, "property", "og:image") != meta(pg, "property", "og:image"))
    nd = get(anon, "/no-description").text
    check("without excerpt or description the site description is used", meta(nd, "name", "description") == "A site about law and notes")
    ab = get(anon, "/about-us").text
    check("pages are og:type website without article times", meta(ab, "property", "og:type") == "website" and meta(ab, "property", "article:published_time") is None and link(ab, "canonical") == BASE + "/about-us")
    ch = get(anon, "/law-book/part-one").text
    check("a child page's canonical and breadcrumb use the full address", link(ch, "canonical") == BASE + "/law-book/part-one" and meta(ch, "property", "og:url") == BASE + "/law-book/part-one")
    hi = get(anon, "/%E0%A4%AD%E0%A4%BE%E0%A4%B0%E0%A4%A4%E0%A5%80%E0%A4%AF-%E0%A4%B8%E0%A4%82%E0%A4%B5%E0%A4%BF%E0%A4%A7%E0%A4%BE%E0%A4%A8/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6-21").text
    check("a Hindi address gets a percent-encoded canonical", (link(hi, "canonical") or "").startswith(BASE + "/%E0%A4%AD") and "/%E0%A4%85%E0%A4%A8" in link(hi, "canonical"))
    qa = get(anon, "/quiet-article").text
    check("a noindex page: robots noindex, no canonical, no social tags", meta(qa, "name", "robots") == "noindex, nofollow" and link(qa, "canonical") is None and meta(qa, "property", "og:title") is None and meta(qa, "name", "twitter:card") is None)
    v = requests.Session()
    r = post(v, "/private-papers", {"password": "open-sesame-1"}, token_from="/private-papers")
    pv = get(v, "/private-papers").text
    check("a private page (unlocked): noindex, nothing for previews, no tag links", "privateword" in pv and meta(pv, "name", "robots") == "noindex, nofollow" and meta(pv, "property", "og:title") is None and link(pv, "canonical") is None and "only-private" not in pv and 'rel="tag"' not in pv)
    check("the password form of a private page is noindex and shows no tags either", meta(get(requests.Session(), "/private-papers").text, "name", "robots") == "noindex, nofollow")
    home = get(anon, "/").text
    check("home: canonical /, website, site description, search form in the header", link(home, "canonical") == BASE + "/" and meta(home, "property", "og:type") == "website" and meta(home, "property", "og:title") == "Test Site" and meta(home, "name", "description") == "A site about law and notes" and 'role="search"' in home)
    check("home page 2: canonical carries ?page=2", link(get(anon, "/?page=2").text, "canonical") == BASE + "/?page=2")
    ev = get(anon, "/%22%3E%3Cscript%3Ealert%281%29%3C%2Fscript%3E-evil").text if False else None
    evil = sql("SELECT path FROM cms_content WHERE title LIKE '%Evil'")
    r = get(anon, "/" + requests.utils.quote(evil, safe=""))
    check("a hostile title cannot break out of the head (escaped everywhere)", r.status_code == 200 and "<script>alert(1)" not in r.text and "&lt;script&gt;alert(1)&lt;/script&gt;" in r.text and 'content=""><script' not in r.text)
    nf = get(anon, "/no-such-page")
    check("404 pages are noindex", nf.status_code == 404 and meta(nf.text, "name", "robots") == "noindex, nofollow")
    check("title tag: ordinary article", "<title>Alpha Law | Test Site</title>" in pg)

    print("robots.txt")
    r = get(anon, "/robots.txt")
    check("robots.txt: plain text, blocks admin and search, points to the sitemap", r.status_code == 200 and r.headers["Content-Type"].startswith("text/plain") and "Disallow: /admin/" in r.text and "Disallow: /search" in r.text and ("Sitemap: " + BASE + "/sitemap.xml") in r.text and "max-age" in r.headers["Cache-Control"])
    check("robots.txt: HEAD has no body, POST refused", requests.head(BASE + "/robots.txt").text == "" and anon.post(BASE + "/robots.txt", data={}, allow_redirects=False).status_code == 405)

    print("sitemap.xml")
    r = get(anon, "/sitemap.xml")
    check("sitemap: 200, XML, cacheable, with an ETag", r.status_code == 200 and r.headers["Content-Type"].startswith("application/xml") and r.headers.get("ETag") and "max-age" in r.headers["Cache-Control"])
    root_el = ET.fromstring(r.content)
    locs = [u.find(SM + "loc").text for u in root_el.findall(SM + "url")]
    check("sitemap is valid XML in the sitemap namespace with absolute URLs", root_el.tag == SM + "urlset" and all(l.startswith(BASE + "/") for l in locs))
    check("home, articles, pages and hierarchical child addresses are listed", BASE + "/" in locs and BASE + "/alpha-law" in locs and BASE + "/about-us" in locs and BASE + "/law-book" in locs and BASE + "/law-book/part-one" in locs)
    check("the Hindi child is listed with a percent-encoded address", any(l.startswith(BASE + "/%E0%A4%AD") and l.count("/") == 4 for l in locs))
    check("drafts, private pages, noindex pages and trashed items are not listed", not any(x in l for l in locs for x in ["draft-secret", "private-papers", "quiet-article", "trashed-one"]))
    check("no duplicate URLs; every URL has an ISO lastmod", len(locs) == len(set(locs)) and all(re.match(r"^\d{4}-\d{2}-\d{2}T[\d:]+Z$", u.find(SM + "lastmod").text) for u in root_el.findall(SM + "url")))
    etag = r.headers["ETag"]
    r2 = get(anon, "/sitemap.xml", headers={"If-None-Match": etag})
    check("conditional request: 304 with no body", r2.status_code == 304 and r2.content == b"")
    check("HEAD: headers only; POST refused", requests.head(BASE + "/sitemap.xml").status_code == 200 and requests.head(BASE + "/sitemap.xml").text == "" and anon.post(BASE + "/sitemap.xml", data={}, allow_redirects=False).status_code == 405)
    a_id = int(sql("SELECT id FROM cms_content WHERE title='Alpha Law'"))
    edit(s, a_id, slug="alpha-law-renamed", tags="law, notes", excerpt="Alpha excerpt text")
    r3 = get(anon, "/sitemap.xml")
    locs3 = [u.find(SM + "loc").text for u in ET.fromstring(r3.content).findall(SM + "url")]
    check("editing content changes the ETag; the sitemap shows the new address only", r3.headers["ETag"] != etag and BASE + "/alpha-law-renamed" in locs3 and BASE + "/alpha-law" not in locs3, (etag, r3.headers["ETag"], BASE + "/alpha-law-renamed" in locs3, BASE + "/alpha-law" in locs3))
    rr = get(anon, "/alpha-law")
    check("the old address redirects (301) to the new one", rr.status_code == 301 and rr.headers["Location"].endswith("/alpha-law-renamed"))

    print("feed.xml")
    r = get(anon, "/feed.xml")
    check("feed: RSS content type, ETag, cacheable", r.status_code == 200 and r.headers["Content-Type"].startswith("application/rss+xml") and r.headers.get("ETag") and "max-age" in r.headers["Cache-Control"])
    rss = ET.fromstring(r.content)
    ch_ = rss.find("channel")
    items = ch_.findall("item")
    titles = [i.find("title").text for i in items]
    check("feed: valid RSS 2.0 with channel title, link, language and description", rss.tag == "rss" and rss.get("version") == "2.0" and ch_.find("title").text == "Test Site" and ch_.find("link").text == BASE + "/" and ch_.find("language").text == "en" and ch_.find("description").text == "A site about law and notes")
    check("feed: articles only (17 qualify: no pages, books, drafts, private, noindex or trashed)", len(items) == 17 and "About Us" not in titles and "Law Book" not in titles, len(items))
    check("feed: drafts, private, noindex and trashed items are absent", not any(t in titles for t in ["Draft Secret", "Private Papers", "Quiet Article", "Trashed One"]))
    dates = [__import__("email.utils").utils.parsedate_to_datetime(i.find("pubDate").text) for i in items]
    check("feed: RFC 822 dates in descending order", dates == sorted(dates, reverse=True))
    alpha = [i for i in items if i.find("title").text == "Alpha Law"]
    all_titles = [i.find("title").text for i in ET.fromstring(get(anon, "/feed.xml").content).find("channel").findall("item")]
    check("feed items link to the current address with a permalink guid", all(i.find("link").text.startswith(BASE + "/") and i.find("guid").get("isPermaLink") == "true" and i.find("guid").text == i.find("link").text for i in items))
    r2 = get(anon, "/feed.xml", headers={"If-None-Match": r.headers["ETag"]})
    check("feed: conditional request gives 304", r2.status_code == 304 and r2.content == b"")
    check("feed items carry their tags as categories", any(i.findall("category") for i in items) and "law" in [c.text for i in items for c in i.findall("category")])

    print("Without a site URL")
    settings(s, site_url="")
    check("sitemap and feed explain what is missing (503) instead of guessing a host", get(anon, "/sitemap.xml").status_code == 503 and "site URL" in get(anon, "/sitemap.xml").text and get(anon, "/feed.xml").status_code == 503)
    rb = get(anon, "/robots.txt")
    pgn = get(anon, "/seo-titled").text
    check("robots.txt still works without a Sitemap line; pages omit canonical, og:url, image and the feed link", rb.status_code == 200 and "Sitemap:" not in rb.text and link(pgn, "canonical") is None and meta(pgn, "property", "og:url") is None and meta(pgn, "property", "og:image") is None and link(pgn, "alternate") is None and meta(pgn, "name", "description") == "Custom meta description")
    settings(s)
    check("restoring the site URL restores them", get(anon, "/sitemap.xml").status_code == 200 and link(get(anon, "/seo-titled").text, "canonical") == BASE + "/seo-titled")
    check("settings screen lists the three addresses", all(x in get(s, "/admin/settings").text for x in ["/sitemap.xml", "/feed.xml", "/robots.txt"]))

    print("Tag pages")
    r = get(anon, "/tag/law")
    check("tag page lists public items with that tag (10 per page)", r.status_code == 200 and r.text.count("<article") == 10 and "Tag: law" in r.text and link(r.text, "canonical") == BASE + "/tag/law")
    both = r.text + get(anon, "/tag/law?page=2").text
    check("tag pages include articles and hierarchical child pages (16 public items over two pages)", "Law Filler" in both and 'href="/law-book/part-one"' in both and "Quiet Article" in both, both.count("<article"))
    check("tag pages paginate; the pager keeps the tag address; canonical carries the page", "Page 2 of" in get(anon, "/tag/law?page=2").text and link(get(anon, "/tag/law?page=2").text, "canonical") == BASE + "/tag/law?page=2" and 'href="/tag/law?page=2" rel="next"' in r.text)
    tagpage = get(anon, "/tag/law").text + get(anon, "/tag/law?page=2").text
    check("drafts, private, noindex-free? (noindex pages are listed on the site) and trashed items: drafts, private and trashed never appear", not any(x in tagpage for x in ["Draft Secret", "Private Papers", "Trashed One"]))
    check("a page beyond the last is 404", get(anon, "/tag/law?page=99").status_code == 404 and get(anon, "/tag/law?page=0").status_code == 200)
    for t in ["secret-tag", "only-private", "no-such-tag"]:
        check("tag with no public items (or unknown) is 404, so nothing leaks: " + t, get(anon, "/tag/" + t).status_code == 404)
    rr = get(anon, "/tag/LAW")
    check("different case redirects to the canonical tag address", rr.status_code == 301 and rr.headers["Location"].endswith("/tag/law"))
    check("Hindi tag pages work", get(anon, "/tag/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%9A%E0%A5%8D%E0%A4%9B%E0%A5%87%E0%A4%A6").status_code == 200)
    art = get(anon, "/law-filler-01").text
    check("article pages show their tags as links", 'href="/tag/law" rel="tag">law</a>' in art)
    check("a hostile tag name is escaped on the page", 'rel="tag">evil</a>' in get(anon, "/" + requests.utils.quote(sql("SELECT path FROM cms_content WHERE title LIKE '%Evil'"), safe="")).text)
    check("tag pages: POST refused, noindex not set", anon.post(BASE + "/tag/law", data={}, allow_redirects=False).status_code == 405 and meta(r.text, "name", "robots") is None)
    lw = int(sql("SELECT id FROM cms_tags WHERE name='law'"))
    post(s, "/admin/tags/%d/delete" % lw, {}, token_from="/admin/tags")
    check("deleting a tag in the admin removes its page and the links", get(anon, "/tag/law").status_code == 404 and 'rel="tag">law<' not in get(anon, "/law-filler-01").text)

    print("Search")
    r = get(anon, "/search")
    check("search page without a query: form and a hint, noindex follow, no canonical", r.status_code == 200 and "Type at least 2 characters" in r.text and meta(r.text, "name", "robots") == "noindex, follow" and link(r.text, "canonical") is None)
    r = get(anon, "/search", params={"q": "lawword"})
    check("search results: count, titles, 10 per page", "results for" in r.text and r.text.count("<article") == 10 and "Law Filler" in r.text)
    check("search pagination keeps the query", 'href="/search?q=lawword&amp;page=2" rel="next"' in r.text and "Page 2 of" in get(anon, "/search", params={"q": "lawword", "page": "2"}).text)
    check("results include hierarchical child pages", 'href="/law-book/part-one"' in get(anon, "/search", params={"q": "part one lawword"}).text)
    check("Hindi search works", "अनुच्छेद 21" in get(anon, "/search", params={"q": "स्वतंत्रता"}).text and 'href="/%E0%A4%AD' in get(anon, "/search", params={"q": "स्वतंत्रता"}).text)
    check("drafts, private and trashed content are never found", all("No results" in get(anon, "/search", params={"q": w}).text for w in ["draftword", "privateword", "trashedword"]))
    check("no results: a friendly message", "No results for" in get(anon, "/search", params={"q": "zzzqqq"}).text)
    check("a one-letter query asks for more", "Type at least 2 characters" in get(anon, "/search", params={"q": "a"}).text)
    xs = get(anon, "/search", params={"q": "<script>alert(1)</script>"})
    check("the query is escaped in the page, the title and the form", "<script>alert(1)</script>" not in xs.text and "&lt;script&gt;alert(1)&lt;/script&gt;" in xs.text)
    check("odd input is harmless", all(get(anon, "/search", params=p).status_code in (200, 404) for p in [{"q": "x" * 5000}, {"q": "lawword", "page": "abc"}, {"q": "lawword", "page": "-5"}, {"q": ["a", "b"]}, {"q": "%%%%"}]))
    check("a result page beyond the end is 404; POST refused", get(anon, "/search", params={"q": "lawword", "page": "99"}).status_code == 404 and anon.post(BASE + "/search", data={"q": "x"}, allow_redirects=False).status_code == 405)
    form = get(anon, "/alpha-law-renamed").text
    check("every public page has the search box (GET form to /search)", re.search(r'<form class="d-flex" role="search" action="/search" method="get">', form) is not None and 'minlength="2"' in form)
    check("the search page content-security policy still allows only same-site forms and no inline script", "form-action 'self'" in get(anon, "/search").headers["Content-Security-Policy"])

    print("Housekeeping")
    perr = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
    check("no PHP warnings or errors in the PHP error log", not perr, perr)

if __name__ == "__main__":
    {"partA": partA, "partB": partB}[sys.argv[1]]()
    print("\n%d passed, %d failed" % (res["pass"], res["fail"]))
    sys.exit(1 if res["fail"] else 0)
