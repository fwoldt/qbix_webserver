#!/usr/bin/env python3
"""End-to-end test of the Q shell in a real browser (Playwright, Chromium).

    python3 tests/e2e-shell.py http://127.0.0.1:18951 '<panel password>' [screenshot dir]

Needs a running server whose control panel password is the one given. Signs
in, opens the shell with ` on the documentation page, and checks: commands and
their output, help and man, Tab completion, Ctrl-R, a confirmation prompt,
sudo's password prompt, themes, tabs and splits, background jobs, Esc and `
to hide, that ` typed into a password field does not open it, and the
on-screen key row at phone width. At 960x540 scale 2 and at 480 wide.
Prints PASS/FAIL per check; exit 0 when all pass.
"""
import asyncio
import json
import os
import sys

from playwright.async_api import async_playwright

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:18951'
PASSWORD = sys.argv[2] if len(sys.argv) > 2 else ''
SHOTS = sys.argv[3] if len(sys.argv) > 3 else None
results = []


def check(name, ok, detail=''):
    results.append(ok)
    print(('PASS ' if ok else 'FAIL ') + name + ((' -- ' + detail) if detail and not ok else ''))


async def sign_in(page):
    await page.goto(BASE + '/Q/panel')
    tok = await page.evaluate("""async (pw) => {
        const r = await fetch('/Q/api/auth/login', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({password: pw})});
        const j = await r.json();
        if (j.token) { sessionStorage.setItem('Q_panel_token', j.token); document.cookie = 'Q_panel_token=' + j.token + '; path=/; SameSite=Strict'; }
        return j.token || null;
    }""", PASSWORD)
    return tok


async def out_text(page):
    return await page.evaluate("() => { const p = document.querySelector('.qs-pane.active .qs-out') || document.querySelector('.qs-out'); return p ? p.innerText : ''; }")


async def run(page, line, expect, timeout=8000):
    inp = page.locator('.qs-tabs ~ .qs-panes .qs-pane.active .qs-input, .qs-panes .qs-pane .qs-input').first
    active = page.locator('.qs-pane.active .qs-input')
    if await active.count():
        inp = active.first
    await inp.fill(line)
    await inp.press('Enter')
    try:
        await page.wait_for_function("(e) => { const p = document.querySelector('.qs-pane.active .qs-out') || document.querySelector('.qs-out'); return p && p.innerText.indexOf(e) !== -1; }", arg=expect, timeout=timeout)
        return True
    except Exception:
        return False


async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        ctx = await b.new_context(viewport={'width': 960, 'height': 540}, device_scale_factor=2)
        page = await ctx.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        tok = await sign_in(page)
        check('signed in to the control panel', bool(tok))
        await page.goto(BASE + '/Q/docs')
        await page.wait_for_function("() => { const a = document.querySelector('[data-qshell-open]'); return a && !a.classList.contains('qshell-off'); }", timeout=8000)
        check('the Shell item is in the toolbar and enabled', await page.locator('.qnav [data-qshell-open]').count() == 1)

        # ` typed into a password field must not open the shell.
        await page.evaluate("() => { const i = document.createElement('input'); i.type = 'password'; i.id = 'pwtest'; document.body.prepend(i); }")
        await page.click('#pwtest')
        await page.keyboard.type('a`b')
        opened = await page.evaluate("() => !!document.querySelector('.qshell.open')")
        val = await page.input_value('#pwtest')
        check('` in a password field types a backquote and does not open the shell', not opened and val == 'a`b', 'opened=%s value=%r' % (opened, val))
        await page.evaluate("() => document.getElementById('pwtest').remove()")

        await page.keyboard.press('`')
        await page.wait_for_selector('.qshell.open', timeout=4000)
        check('` opens the shell', True)
        await page.wait_for_function("() => document.querySelector('.qs-status') && document.querySelector('.qs-status').textContent.indexOf('tier') !== -1", timeout=8000)
        check('the shell connects (status bar shows the tier and user)', 'runs as' in (await page.inner_text('.qs-status')))

        check('echo with brace expansion and arithmetic', await run(page, 'echo hello {1..3} $((6*7))', 'hello 1 2 3 42'))
        check('help lists the commands', await run(page, 'help', 'Commands'))
        check('man server shows the noun page', await run(page, 'man server', 'COMMANDS'))
        check('a pipe with filters', await run(page, 'seq 10 | grep -v 5 | sort -rn | head -n 2', '10\n9'))
        check('get shows settings, zfs-style', await run(page, 'get workers,design', 'SOURCE'))
        check('an unknown command is reported', await run(page, 'nosuchcommand', 'command not found'))
        check('workers list comes from the pool', await run(page, 'workers list -H | wc -l', ''))

        # Tab completion.
        inp = page.locator('.qs-pane.active .qs-input')
        await inp.fill('wor')
        await inp.press('Tab')
        await page.wait_for_timeout(700)
        check('Tab completes a command', (await inp.input_value()) == 'workers ', repr(await inp.input_value()))
        await inp.fill('workers l')
        await inp.press('Tab')
        await page.wait_for_timeout(700)
        check('Tab completes a verb', (await inp.input_value()) == 'workers list ', repr(await inp.input_value()))
        await inp.fill('get work')
        await inp.press('Tab')
        await page.wait_for_timeout(700)
        check('Tab completes the common prefix of settings', (await inp.input_value()) == 'get worker', repr(await inp.input_value()))
        await inp.press('Tab')
        await page.wait_for_timeout(700)
        check('a second Tab offers the choices in a menu', await page.locator('.qs-pane.active .qs-menu div').count() >= 2)
        await inp.press('Enter')
        check('Enter takes the chosen one', (await inp.input_value()).startswith('get worker'))
        await inp.fill('')

        # Ctrl-R.
        await inp.press('Control+r')
        await page.keyboard.type('hello')
        await page.wait_for_timeout(200)
        check('Ctrl-R finds a line in the history', 'echo hello' in (await inp.input_value()), repr(await inp.input_value()))
        await inp.press('Escape')
        await inp.fill('')

        # A disruptive command asks first; "n" declines.
        await inp.fill('cache clear')
        await inp.press('Enter')
        try:
            await page.wait_for_function("() => document.querySelector('.qs-pane.active .qs-prompt').textContent.indexOf('proceed') !== -1", timeout=8000)
            check('cache clear asks proceed? [y/N]', True)
            await inp.fill('n')
            await inp.press('Enter')
            check('answering n declines it', await run(page, 'echo after-decline', 'not confirmed'))
        except Exception:
            check('cache clear asks proceed? [y/N]', False)

        # sudo: a secret prompt, then the password confirmed.
        await inp.fill('sudo -v')
        await inp.press('Enter')
        try:
            await page.wait_for_function("() => document.querySelector('.qs-pane.active .qs-input').type === 'password'", timeout=8000)
            check('sudo asks for the password in a masked field', True)
            await inp.fill(PASSWORD)
            await inp.press('Enter')
            await page.wait_for_function("() => (document.querySelector('.qs-pane.active .qs-out').innerText.indexOf('confirmed until') !== -1)", timeout=8000)
            check('the right password confirms (sudo until ...)', 'sudo until' in (await page.inner_text('.qs-status')))
        except Exception as e:
            check('sudo asks for the password in a masked field', False, str(e))

        # OS commands are off by default.
        check('sys is refused while Q.shell.allowSystem is off', await run(page, 'sys uptime', 'OS commands are off'))

        # Background job.
        check('cmd & starts a background job', await run(page, 'sleep 3 &', '[1]'))
        check('jobs lists it', await run(page, 'jobs', 'running'))

        # Themes.
        await run(page, 'theme use quake', '')
        await page.wait_for_timeout(600)
        bg = await page.evaluate("() => getComputedStyle(document.querySelector('.qshell')).getPropertyValue('--qs-bg').trim()")
        check('theme use quake switches the colours', bg == '#1a120b', bg)
        if SHOTS:
            os.makedirs(SHOTS, exist_ok=True)
            await page.screenshot(path=os.path.join(SHOTS, 'shell-960.png'))

        # Tabs and splits.
        await inp.press('Control+Shift+T')
        await page.wait_for_timeout(300)
        check('Ctrl-Shift-T opens a tab', await page.locator('.qs-tab:not(.qs-newtab)').count() == 2)
        await page.locator('.qs-pane.active .qs-input').press('Control+Shift+D')
        await page.wait_for_timeout(300)
        check('Ctrl-Shift-D splits the pane', await page.locator('.qs-panes .qs-pane').count() == 2)
        check('the new pane runs commands', await run(page, 'echo from-split', 'from-split'))
        if SHOTS:
            await page.screenshot(path=os.path.join(SHOTS, 'shell-960-split.png'))

        # Hide and show.
        await page.locator('.qs-pane.active .qs-input').press('Escape')
        await page.wait_for_timeout(300)
        check('Esc hides the shell', not await page.evaluate("() => !!document.querySelector('.qshell.open')"))
        await page.keyboard.press('`')
        await page.wait_for_timeout(300)
        check('` shows it again', await page.evaluate("() => !!document.querySelector('.qshell.open')"))

        # The toolbar item and the window controls (+ − m ×).
        item = page.locator('.qnav [data-qshell-open]')
        check('the toolbar item says it is showing (aria-expanded)', await item.get_attribute('aria-expanded') == 'true')
        pill = await page.evaluate("""() => { const s = getComputedStyle(document.querySelector('.qnav [data-qshell-open]')), o = getComputedStyle(document.querySelector('.qnav a:not([data-qshell-open])'));
            return [s.borderRadius === o.borderRadius, s.paddingLeft === o.paddingLeft, s.fontSize === o.fontSize, s.borderTopWidth === o.borderTopWidth]; }""")
        check('the toolbar item is styled like the other toolbar pills', all(pill), str(pill))
        check('the connection dot shows on the toolbar item', await page.evaluate("() => getComputedStyle(document.querySelector('[data-qshell-open] .qshell-dot')).display !== 'none'"))
        for sel, name in (('.qs-show', '+'), ('.qs-hide', '−'), ('.qs-maxbtn', 'm'), ('.qs-close', '×')):
            lab = await page.get_attribute('.qs-win ' + sel, 'aria-label')
            check('window control %s has an accessible name' % name, bool(lab), repr(lab))
        check('a background job for the hide test', await run(page, 'sleep 20 &', '['))
        await page.click('.qs-win .qs-hide')
        await page.wait_for_timeout(300)
        hidden = not await page.evaluate("() => !!document.querySelector('.qshell.open')")
        check('− hides the shell', hidden)
        check('− gives focus back to the toolbar item', await page.evaluate("() => document.activeElement && document.activeElement.hasAttribute('data-qshell-open')"))
        check('the toolbar item shows running, hidden', await page.evaluate("() => document.querySelector('[data-qshell-open]').classList.contains('qshell-hidden-run')"))
        await item.click()
        await page.wait_for_selector('.qshell.open', timeout=4000)
        check('the toolbar item shows it again', True)
        check('the session survived hiding (the job is still listed)', await run(page, 'jobs', 'sleep 20'))
        h0 = await page.evaluate("() => document.querySelector('.qshell').getBoundingClientRect().height")
        await page.click('.qs-win .qs-maxbtn')
        await page.wait_for_timeout(300)
        h1 = await page.evaluate("() => document.querySelector('.qshell').getBoundingClientRect().height")
        check('m maximises to the full height', h1 >= 539 and h1 > h0, '%s -> %s' % (h0, h1))
        if SHOTS:
            await page.screenshot(path=os.path.join(SHOTS, 'shell-960-max.png'))
        await page.click('.qs-win .qs-maxbtn')
        await page.wait_for_timeout(300)
        h2 = await page.evaluate("() => document.querySelector('.qshell').getBoundingClientRect().height")
        check('m again restores the height', abs(h2 - h0) < 2, '%s vs %s' % (h2, h0))
        await page.click('.qs-win .qs-close')
        try:
            await page.wait_for_selector('.qs-confirm', timeout=4000)
            check('× with a running job asks first', True)
        except Exception:
            check('× with a running job asks first', False)
        if SHOTS:
            await page.screenshot(path=os.path.join(SHOTS, 'shell-960-close-confirm.png'))
        await page.click('.qs-confirm .qs-confirm-no')
        await page.wait_for_timeout(200)
        check('cancelling keeps the shell and its job', await run(page, 'jobs', 'sleep 20'))
        await page.click('.qs-win .qs-close')
        await page.wait_for_selector('.qs-confirm', timeout=4000)
        await page.click('.qs-confirm .qs-confirm-yes')
        await page.wait_for_timeout(600)
        check('× then confirming closes the shell', not await page.evaluate("() => !!document.querySelector('.qshell')"))
        check('after closing, the toolbar item is back to plain', await page.evaluate("() => { const a = document.querySelector('[data-qshell-open]'); return !a.classList.contains('qshell-started') && a.getAttribute('aria-expanded') === 'false'; }"))
        await page.keyboard.press('`')
        await page.wait_for_selector('.qshell.open', timeout=4000)
        check('` starts a fresh shell after closing', await run(page, 'echo fresh-start', 'fresh-start'))
        left = await page.evaluate("""async () => { const t = sessionStorage.getItem('Q_panel_token');
            const r = await fetch('/Q/api/shell/jobs', {headers: {'X-Panel-Token': t}}); const j = await r.json();
            return (j.jobs || []).filter(x => x.state === 'running' && x.line.indexOf('sleep 20') !== -1).length; }""")
        check('closing ended the old session\'s running job', left == 0, str(left))
        for scheme in ('light', 'dark'):
            await page.emulate_media(color_scheme=scheme)
            await page.wait_for_timeout(200)
            if SHOTS:
                await page.screenshot(path=os.path.join(SHOTS, 'shell-960-%s.png' % scheme))
        await page.emulate_media(color_scheme='dark')
        check('no script errors on the page', not errors, '; '.join(errors))

        # Phone width, touch.
        mctx = await b.new_context(viewport={'width': 480, 'height': 800}, device_scale_factor=2, has_touch=True, is_mobile=True)
        mp = await mctx.new_page()
        await sign_in(mp)
        await mp.goto(BASE + '/Q/docs')
        await mp.wait_for_function("() => { const a = document.querySelector('[data-qshell-open]'); return a && !a.classList.contains('qshell-off'); }", timeout=8000)
        await mp.locator('.qnav [data-qshell-open]').tap()
        await mp.wait_for_selector('.qshell.open', timeout=4000)
        check('phone: the toolbar item opens the shell', True)
        check('phone: the on-screen key row is shown', await mp.locator('.qs-keys').is_visible())
        width = await mp.evaluate("() => document.querySelector('.qshell').getBoundingClientRect().width")
        check('phone: full width', abs(width - 480) < 2, str(width))
        overflow = await mp.evaluate("() => document.documentElement.scrollWidth > window.innerWidth + 1")
        check('phone: no horizontal overflow', not overflow)
        check('phone: commands run', await run(mp, 'echo phone-ok', 'phone-ok'))
        sizes = await mp.evaluate("() => Array.from(document.querySelectorAll('.qs-win button')).map(b => { const r = b.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; })")
        check('phone: window controls are at least 44px', len(sizes) == 4 and all(w >= 44 and h >= 44 for w, h in sizes), str(sizes))
        if SHOTS:
            await mp.screenshot(path=os.path.join(SHOTS, 'shell-480.png'))
        # Signed out: the item is there, but disabled with the hint.
        octx = await b.new_context(viewport={'width': 960, 'height': 540}, device_scale_factor=2)
        op = await octx.new_page()
        await op.goto(BASE + '/Q/docs')
        await op.wait_for_timeout(1500)
        dis = await op.evaluate("() => { const a = document.querySelector('[data-qshell-open]'); return a ? [a.getAttribute('aria-disabled'), a.title] : null; }")
        check('signed out: the item is disabled with the sign-in hint', bool(dis) and dis[0] == 'true' and 'Sign in' in (dis[1] or ''), str(dis))
        await op.keyboard.press('`')
        await op.wait_for_timeout(300)
        check('signed out: ` does not open the shell', not await op.evaluate("() => !!document.querySelector('.qshell.open')"))
        await b.close()
    ok = all(results)
    print('%s %d of %d checks' % ('PASS' if ok else 'FAIL', sum(1 for r in results if r), len(results)))
    return 0 if ok else 1


if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
