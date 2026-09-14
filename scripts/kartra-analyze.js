/**
 * Quick DOM analysis script — logs the HTML of the portal index
 * and all links/accordion items to understand the structure.
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORTAL_URL = 'https://besafe.kartra.com/portal/Lion';
const EMAIL      = 'john@ihub.global';
const PASSWORD   = 'peZMDgQs';

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
    const browser = await chromium.launch({ headless: true });
    const ctx     = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        viewport:  { width: 1280, height: 900 },
    });

    // Capture ALL XHR/fetch responses to look for API calls
    const apiCalls = [];
    const page = await ctx.newPage();
    page.on('response', async (response) => {
        const url = response.url();
        const ct  = response.headers()['content-type'] || '';
        if (ct.includes('application/json') || ct.includes('text/json')) {
            try {
                const body = await response.json().catch(() => null);
                if (body) apiCalls.push({ url, body });
            } catch {}
        }
    });

    console.log('Navigating…');
    await page.goto(PORTAL_URL, { waitUntil: 'networkidle', timeout: 60000 });
    await sleep(2000);

    const passVisible = await page.$eval('input[type="password"]', el => {
        const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0;
    }).catch(() => false);

    if (passVisible) {
        console.log('Logging in…');
        const emailEl = await page.$('input#username') || await page.$('input[type="text"]:visible');
        const passEl  = await page.$('input#password')  || await page.$('input[type="password"]');
        if (emailEl) { await emailEl.click({ clickCount: 3 }); await emailEl.fill(EMAIL); }
        if (passEl)  { await passEl.click(); await passEl.fill(PASSWORD); }
        const submit = await page.$('button[type="submit"], input[type="submit"]');
        if (submit) await submit.click(); else await passEl?.press('Enter');
        await Promise.race([page.waitForURL('**/index**', { timeout: 30000 }), sleep(15000)]).catch(() => {});
        await sleep(4000);
    }

    console.log('URL:', page.url());

    // ── Dump all links ──────────────────────────────────────────────────────
    const links = await page.$$eval('a[href]', els =>
        els.map(a => ({
            href: a.href,
            text: a.textContent.trim().substring(0, 80),
            classes: a.className,
        })).filter(l => l.href && l.href.startsWith('http'))
    );
    console.log('\n=== ALL LINKS ===');
    links.forEach(l => console.log(`  [${l.text}] → ${l.href}`));

    // ── Find accordion/category items ───────────────────────────────────────
    const catItems = await page.$$eval('[class*="category"], [class*="accordion"], [class*="module"], [class*="topic"]', els =>
        els.slice(0, 20).map(el => ({
            tag: el.tagName,
            classes: el.className.substring(0, 100),
            text: el.textContent.trim().substring(0, 80),
            hasLinks: el.querySelectorAll('a').length,
        }))
    );
    console.log('\n=== CATEGORY/ACCORDION ELEMENTS (first 20) ===');
    catItems.forEach(c => console.log(`  <${c.tag} class="${c.classes}"> "${c.text}" links=${c.hasLinks}`));

    // ── Try clicking the first expandable module ────────────────────────────
    console.log('\n=== Trying to click first module to expand ===');
    const clickables = await page.$$('[class*="category"] a, [class*="module"] a, .panel-title, .accordion-toggle, [data-toggle], [class*="toggle"]');
    if (clickables.length > 0) {
        const text = await clickables[0].textContent();
        console.log('Clicking:', text?.trim());
        await clickables[0].click().catch(e => console.log('click error:', e.message));
        await sleep(2000);
        const newLinks = await page.$$eval('a[href]', els =>
            els.map(a => ({ href: a.href, text: a.textContent.trim().substring(0, 60) }))
               .filter(l => l.href.includes('/portal/') && l.text)
        );
        console.log('Links after click:', newLinks.length);
        newLinks.slice(0, 20).forEach(l => console.log(`  [${l.text}] → ${l.href}`));
    }

    // ── Save HTML for offline analysis ──────────────────────────────────────
    const html = await page.content();
    fs.writeFileSync(path.join(__dirname, 'portal-index.html'), html);
    console.log('\n→ Portal HTML saved to scripts/portal-index.html');

    // ── API calls captured ──────────────────────────────────────────────────
    console.log(`\n=== JSON API CALLS (${apiCalls.length}) ===`);
    apiCalls.slice(0, 10).forEach(c => {
        console.log('URL:', c.url);
        console.log('Body:', JSON.stringify(c.body).substring(0, 200));
        console.log('---');
    });
    if (apiCalls.length > 0) {
        fs.writeFileSync(path.join(__dirname, 'portal-api-calls.json'), JSON.stringify(apiCalls, null, 2));
    }

    await browser.close();
    process.exit(0);
})();
