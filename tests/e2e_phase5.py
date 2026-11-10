"""Phase 5 (global settings) end-to-end: install the 4C release, seed content, upgrade to the current code, then test items per page,
time zone and date format, footer text and maintenance mode through the admin and the public site. Driven by tests/e2e_phase5.sh."""
import os, re, sys, html
import requests

os.environ.setdefault("CMS_E2E_ROOT", "/tmp/e2e5")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_upgrade import BASE, ROOT, PW, VERSION, check, res, get, post, csrf, login  # noqa: E402
from e2e_phase4b import item, sql  # noqa: E402

def settings(s, **over):
    data = dict(site_name="Test Site", site_url=BASE, site_lang="en", site_description="", site_logo_media_id="", site_social_media_id="",
                items_per_page="10", timezone="UTC", date_format="j M Y", footer_text="", maintenance_message="")
    data.update(over)
    return post(s, "/admin/settings", data, token_from="/admin/settings")

def count_articles(page): return page.count("<article")

def partA():
    print("Part A: 4C baseline with seeded content")
    s = requests.Session()
    good = dict(db_host="127.0.0.1", db_port="3306", db_name="cmse2e", db_user="cms", db_pass="cmspass", prefix="cms_", site_name="Test Site",
                admin_username="prateek", admin_email="a@example.com", admin_password=PW, admin_password2=PW)
    check("4C installs", "Installation complete" in post(s, "/install.php", good, token_from="/install.php").text)
    a = login()
    for i in range(1, 15):
        item(a, title="Item %02d" % i, tags="law", body="<p>lawword item %d</p>" % i)
    item(a, title="Dated Article", tags="law", body="<p>lawword dated</p>")
    item(a, title="Some Page", type="page", body="<p>page</p>")
    sql("UPDATE cms_content SET published_at='2026-10-01 20:00:00', updated_at='2026-10-01 20:00:00' WHERE title='Dated Article'")
    check("4C has no Display or Maintenance settings yet", "Maintenance mode" not in get(a, "/admin/settings").text)

def partB():
    anon = requests.Session()
    print("Upgrade 4C -> current")
    s = login()
    r = get(s, "/admin/update")
    check("update screen: version only, no database changes", "No database changes are needed" in r.text)
    r = post(s, "/admin/update/apply", {}, token_from="/admin/update")
    check("update applies", r.status_code == 303 and VERSION in open(ROOT + "/user/config/app.php").read())

    print("Settings screen")
    page = get(s, "/admin/settings").text
    check("new Display and Maintenance cards with the defaults", "Items per page" in page and "Maintenance mode" in page and 'name="items_per_page" value="10"' in page and 'name="timezone" value="UTC"' in page and 'name="maintenance_mode" value="1"' in page and 'value="j M Y" selected' in page)
    check("the time-zone box offers the zone list", "Asia/Kolkata" in page and '<datalist id="zone-list">' in page)
    r = settings(s, items_per_page="3", timezone="Mars/Base", date_format="Y; drop", footer_text="x" * 301, maintenance_message="m" * 501)
    check("invalid values are rejected (422) with a message for each field", r.status_code == 422 and all(t in r.text for t in ["Items per page must be", "Choose a time zone", "Choose one of the listed date formats", "footer text is too long", "maintenance message is too long"]))
    check("the form keeps what you typed", 'name="items_per_page" value="3"' in r.text and 'value="Mars/Base"' in r.text)
    check("nothing was saved by the failed attempt", sql("SELECT COUNT(*) FROM cms_settings WHERE setting_key='timezone'") == "0")
    check("saving needs a CSRF token", s.post(BASE + "/admin/settings", data={"site_name": "x"}, allow_redirects=False).status_code == 403)

    print("Items per page")
    check("default is 10 per page on home, tag and search", count_articles(get(anon, "/").text) == 10 and count_articles(get(anon, "/tag/law").text) == 10 and count_articles(get(anon, "/search", params={"q": "lawword"}).text) == 10)
    settings(s, items_per_page="5")
    h = get(anon, "/").text
    check("5 per page: home, tag and search all follow (pager shows 3 pages for 15 articles)", count_articles(h) == 5 and "Page 1 of 3" in h and count_articles(get(anon, "/tag/law").text) == 5 and count_articles(get(anon, "/search", params={"q": "lawword"}).text) == 5)
    check("page 3 exists and page 4 is not found", get(anon, "/?page=3").status_code == 200 and get(anon, "/?page=4").status_code == 404)
    settings(s, items_per_page="50")
    check("50 per page: everything on one page, no pager", count_articles(get(anon, "/").text) == 15 and "Page 1 of" not in get(anon, "/").text)
    for bad in ["abc", "3", "99", ""]:
        sql("REPLACE INTO cms_settings (setting_key, setting_value, updated_at) VALUES ('items_per_page', '%s', NOW())" % bad)
        check("a corrupted stored value (%r) falls back to 10 instead of breaking the site" % bad, get(anon, "/").status_code == 200 and count_articles(get(anon, "/").text) == 10)
    settings(s)

    print("Time zone and date format")
    dated = int(sql("SELECT id FROM cms_content WHERE title='Dated Article'"))
    path = sql("SELECT path FROM cms_content WHERE id=%d" % dated)
    pg = get(anon, "/" + path).text
    check("UTC (default): the 20:00 UTC article is dated 1 Oct 2026", '<time datetime="2026-10-01">1 Oct 2026</time>' in pg)
    settings(s, timezone="Asia/Kolkata")
    pg = get(anon, "/" + path).text
    check("Asia/Kolkata: the same article is dated 2 Oct 2026 (01:30 IST); the machine date agrees", '<time datetime="2026-10-02">2 Oct 2026</time>' in pg)
    check("list pages follow the zone too (home, tag, search)", all('<time datetime="2026-10-02">2 Oct 2026</time>' in x for x in [get(anon, "/").text + get(anon, "/?page=2").text, get(anon, "/tag/law").text + get(anon, "/tag/law?page=2").text, get(anon, "/search", params={"q": "dated"}).text]))
    check("the admin lists show dates in the site zone as well (updated 20:00 UTC = 2 Oct IST)", "2 Oct 2026" in get(s, "/admin/content?q=Dated").text)
    for fmt, shown in [("d/m/Y", "02/10/2026"), ("Y-m-d", "2026-10-02"), ("F j, Y", "October 2, 2026"), ("j F Y", "2 October 2026")]:
        settings(s, timezone="Asia/Kolkata", date_format=fmt)
        check("date format %s shows %s" % (fmt, shown), (">%s</time>" % shown) in get(anon, "/" + path).text)
    settings(s, timezone="Asia/Kolkata")
    feed = get(anon, "/feed.xml").text
    check("the RSS feed and the sitemap stay in UTC as their standards require", "Thu, 01 Oct 2026 20:00:00 +0000" in feed and "2026-10-01T20:00:00Z" in get(anon, "/sitemap.xml").text)
    sql("REPLACE INTO cms_settings (setting_key, setting_value, updated_at) VALUES ('timezone', 'Bad/Zone', NOW()), ('date_format', 'bogus', NOW())")
    pg = get(anon, "/" + path)
    check("an invalid stored zone or format never breaks pages: UTC and the default format are used", pg.status_code == 200 and "1 Oct 2026" in pg.text)
    settings(s)

    print("Footer text")
    check("default footer: copyright line with the site name", re.search(r"© \d{4} Test Site", get(anon, "/").text) is not None)
    settings(s, footer_text="(c) {year} Test & <b>Co</b>. All rights reserved.")
    foot = get(anon, "/some-page").text
    check("custom footer: {year} replaced, text escaped, shown on every page", re.search(r"\(c\) \d{4} Test &amp; &lt;b&gt;Co&lt;/b&gt;\. All rights reserved\.</footer>", foot) is not None and "<b>Co</b>" not in foot and "(c) " in get(anon, "/search").text)
    settings(s)
    check("clearing it restores the default", re.search(r"© \d{4} Test Site", get(anon, "/").text) is not None)

    print("Maintenance mode")
    check("dashboard shows no banner while it is off", "Maintenance mode is on" not in get(s, "/admin").text)
    settings(s, maintenance_mode="1", maintenance_message="Back at 6pm <b>sharp</b> & thanks")
    for p in ["/", "/some-page", "/" + path, "/tag/law", "/search?q=lawword", "/sitemap.xml", "/feed.xml", "/robots.txt", "/no-such-page"]:
        r = get(anon, p)
        check("visitors get 503 at " + p, r.status_code == 503 and r.headers.get("Retry-After") == "3600" and "no-store" in r.headers.get("Cache-Control", ""), r.status_code)
    r = get(anon, "/")
    check("the page shows the escaped message, is not cached and is not indexed", "Back at 6pm &lt;b&gt;sharp&lt;/b&gt; &amp; thanks" in r.text and "<b>sharp</b>" not in r.text and 'name="robots" content="noindex' in r.text and "no-store" in r.headers["Cache-Control"])
    check("POSTs are refused too", anon.post(BASE + "/" + path, data={"password": "x"}, allow_redirects=False).status_code == 503)
    check("static assets keep working (the site is not left unstyled)", get(anon, "/assets/base/css/theme.css").status_code == 200)
    check("the admin sign-in page works for everyone", get(anon, "/admin/login").status_code == 200)
    forged = requests.Session(); forged.cookies.set("cmssid", "attackersuppliedid1234567890abcd", domain="127.0.0.1", path="/")
    check("a visitor with a made-up session cookie is still a visitor", get(forged, "/").status_code == 503)
    check("a signed-in administrator can still browse the public site", get(s, "/").status_code == 200 and get(s, "/" + path).status_code == 200 and "Dated Article" in get(s, "/" + path).text)
    check("the dashboard shows a banner with a link to turn it off", "Maintenance mode is on" in get(s, "/admin").text)
    settings(s, maintenance_mode="1", maintenance_message="")
    check("an empty message shows the default text", "The site is down for maintenance. Please check back soon." in get(anon, "/").text)
    settings(s)
    r = get(anon, "/")
    check("turning it off restores the site at once", r.status_code == 200 and "Retry-After" not in r.headers and get(anon, "/sitemap.xml").status_code == 200)
    check("the banner is gone", "Maintenance mode is on" not in get(s, "/admin").text)
    sql("REPLACE INTO cms_settings (setting_key, setting_value, updated_at) VALUES ('maintenance_mode', 'yes', NOW())")
    check("only the exact value 1 turns it on (a stray value does not lock visitors out)", get(anon, "/").status_code == 200)
    sql("DELETE FROM cms_settings WHERE setting_key='maintenance_mode'")

    print("Housekeeping")
    perr = os.path.exists(ROOT + "/user/logs/php-error.log") and open(ROOT + "/user/logs/php-error.log").read().strip()
    check("no PHP warnings or errors in the PHP error log", not perr, perr)

if __name__ == "__main__":
    {"partA": partA, "partB": partB}[sys.argv[1]]()
    print("\n%d passed, %d failed" % (res["pass"], res["fail"]))
    sys.exit(1 if res["fail"] else 0)
