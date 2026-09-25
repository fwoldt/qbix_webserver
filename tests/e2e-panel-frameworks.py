#!/usr/bin/env python3
"""End-to-end test of the control panel's Apps and Frameworks tabs in a real
browser (Playwright, Chromium).

    python3 tests/e2e-panel-frameworks.py http://127.0.0.1:18961 '<panel password>' '<expected title>' [screenshot dir]

Needs a running server whose control panel password is the one given, serving
a document root the registry recognises. Signs in, and checks: the Apps tab's
Installations list shows the served application first with its title and
"serving on this port"; the Frameworks tab shows the same application with
its details and command buttons; a harmless read-only command runs and its
output appears; a disruptive command asks before it runs, and cancelling runs
nothing; no page overflows sideways. At 960x540 scale 2 and at 480 wide, in
light and dark. Prints PASS/FAIL per check; exit 0 when all pass.
"""
import asyncio
import os
import sys

from playwright.async_api import async_playwright

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:18961'
PASSWORD = sys.argv[2] if len(sys.argv) > 2 else ''
TITLE = sys.argv[3] if len(sys.argv) > 3 else ''
SHOTS = sys.argv[4] if len(sys.argv) > 4 else None
results = []


def check(name, ok, detail=''):
    results.append(ok)
    print(('PASS ' if ok else 'FAIL ') + name + ((' -- ' + detail) if detail and not ok else ''))


async def sign_in(page):
    await page.goto(BASE + '/Q/panel')
    return await page.evaluate("""async (pw) => {
        const r = await fetch('/Q/api/auth/login', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({password: pw})});
        const j = await r.json();
        if (j.token) { sessionStorage.setItem('Q_panel_token', j.token); document.cookie = 'Q_panel_token=' + j.token + '; path=/; SameSite=Strict'; }
        return j.token || null;
    }""", PASSWORD)


async def overflow(page):
    return await page.evaluate("() => document.documentElement.scrollWidth > window.innerWidth + 1")


async def one(browser, width, height, scheme):
    tag = '%d-%s' % (width, scheme)
    ctx = await browser.new_context(viewport={'width': width, 'height': height}, device_scale_factor=2,
                                    color_scheme=scheme)
    page = await ctx.new_page()
    tok = await sign_in(page)
    check(tag + ' signed in', bool(tok))
    await page.goto(BASE + '/Q/panel')
    await page.wait_for_timeout(600)

    # Apps tab: installations, served first.
    await page.click(".tab[onclick=\"showTab('apps')\"]")
    await page.wait_for_function("() => { const e = document.getElementById('installations-list'); return e && e.querySelector('.inst-card'); }", timeout=15000)
    first = await page.evaluate("() => { const c = document.querySelector('#installations-list .inst-card'); return c ? c.innerText : ''; }")
    check(tag + ' apps: the served installation is listed first with its title', TITLE in first, first[:200])
    check(tag + ' apps: marked serving on this port', 'serving on this port' in first, first[:200])
    check(tag + ' apps: no sideways overflow', not await overflow(page))
    if SHOTS:
        await page.screenshot(path=os.path.join(SHOTS, 'panel-apps-%s.png' % tag), full_page=False)

    # Frameworks tab: the same application, with its tools.
    await page.click(".tab[onclick=\"showTab('frameworks')\"]")
    await page.wait_for_function("() => document.querySelector('#fw-list .inst-card')", timeout=15000)
    card = await page.evaluate("() => document.querySelector('#fw-list .inst-card').innerText")
    check(tag + ' frameworks: the application is listed with its title', TITLE in card, card[:200])
    buttons = await page.evaluate("() => Array.from(document.querySelectorAll('#fw-list .inst-card button[data-cmd]')).map(b => b.dataset.cmd)")
    check(tag + ' frameworks: command buttons are shown', len(buttons) >= 3, str(buttons))

    # A read-only command runs, and its output appears.
    ro = await page.evaluate("() => { const b = Array.from(document.querySelectorAll('#fw-list button[data-cmd]')).find(b => !b.dataset.disruptive); return b ? b.dataset.cmd : null; }")
    if ro:
        await page.click('#fw-list button[data-cmd="%s"]' % ro)
        await page.wait_for_function("() => { const p = document.querySelector('#fw-list pre[id^=fw-output-]'); return p && p.textContent && !/^Running/.test(p.textContent); }", timeout=20000)
        out = await page.evaluate("() => document.querySelector('#fw-list pre[id^=fw-output-]').textContent")
        check(tag + ' frameworks: a read-only command (%s) runs and shows its output' % ro,
              len(out.strip()) > 0 and 'could not start' not in out.lower() and 'error' not in out.lower()
              and '[exit ' not in out, out[:300])
    else:
        check(tag + ' frameworks: a read-only command exists', False)

    # A disruptive command asks first; cancelling runs nothing.
    dis = await page.evaluate("() => { const b = document.querySelector('#fw-list button[data-disruptive=\"1\"]'); return b ? b.dataset.cmd : null; }")
    if dis:
        asked = []
        async def on_dialog(d):
            asked.append(d.message)
            await d.dismiss()
        page.on('dialog', on_dialog)
        before = await page.evaluate("() => document.querySelector('#fw-list pre[id^=fw-output-]').textContent")
        await page.click('#fw-list button[data-cmd="%s"]' % dis)
        await page.wait_for_timeout(500)
        after = await page.evaluate("() => document.querySelector('#fw-list pre[id^=fw-output-]').textContent")
        check(tag + ' frameworks: a disruptive command (%s) asks first' % dis, len(asked) == 1, str(asked))
        check(tag + ' frameworks: cancelling it runs nothing', before == after)
    check(tag + ' frameworks: no sideways overflow', not await overflow(page))
    if SHOTS:
        await page.screenshot(path=os.path.join(SHOTS, 'panel-frameworks-%s.png' % tag), full_page=False)
    await ctx.close()


async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch(args=['--no-sandbox'])
        for (w, h) in ((960, 540), (480, 900)):
            for scheme in ('light', 'dark'):
                await one(browser, w, h, scheme)
        await browser.close()
    ok = all(results)
    print('%s - %d of %d checks' % ('PASS' if ok else 'FAIL', sum(1 for r in results if r), len(results)))
    return 0 if ok else 1


if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
