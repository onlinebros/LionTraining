/**
 * Scrapes full page content + downloadable file links from every lesson.
 * Outputs kartra-page-content.json:
 *   [ { kartra_id, title, page_title, content_text, content_html, files: [{display_name, download_id, url}] } ]
 *
 * Usage: node kartra-scrape-content.js
 */

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const PORTAL_URL = 'https://besafe.kartra.com/portal/Lion';
const EMAIL      = 'john@ihub.global';
const PASSWORD   = 'peZMDgQs';
const OUT_FILE   = path.join(__dirname, 'kartra-page-content.json');

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Content extraction logic (runs inside browser) ─────────────────────────
const EXTRACT_CONTENT = () => {
    // 1. Pull text from all Kartra element content blocks
    const blocks = [];
    document.querySelectorAll('.element_block.wysiwyg_printout-def').forEach(el => {
        const html = el.innerHTML || '';
        const text = el.textContent.replace(/\s+/g, ' ').trim();
        if (text.length > 5) blocks.push({ html: html.trim(), text });
    });

    // 2. Fallback: grab from js_highlight_content boxes
    if (blocks.length === 0) {
        document.querySelectorAll('.js_highlight_content').forEach(el => {
            const text = el.textContent.replace(/\s+/g, ' ').trim();
            if (text.length > 5) blocks.push({ html: el.innerHTML.trim(), text });
        });
    }

    // 3. Find all downloadable file links
    const files = [];
    document.querySelectorAll('a[href*="/download/"]').forEach(a => {
        const href  = a.href || '';
        const m     = href.match(/\/download\/(\d+)/);
        const dlId  = m ? m[1] : null;
        const name  = (a.textContent || '').trim() || a.getAttribute('title') || dlId;
        if (dlId) {
            files.push({
                display_name: name,
                download_id:  dlId,
                url:          href,
            });
        }
    });

    // 4. Also look for file links via class patterns (buttons, etc.)
    document.querySelectorAll('a[class*="download"], a[class*="file"], a[href*=".pdf"], a[href*=".doc"], a[href*=".zip"]').forEach(a => {
        const href = a.href || '';
        if (!href.includes('/download/') && href.startsWith('http')) {
            files.push({
                display_name: (a.textContent || '').trim() || href.split('/').pop(),
                download_id:  null,
                url:          href,
            });
        }
    });

    // 5. Page title (Kartra puts the lesson name here)
    const pageTitle = document.title
        .replace(' Membership', '')
        .replace(' - Releasing The-LION', '')
        .trim();

    return {
        page_title:    pageTitle,
        blocks,
        files,
        content_html:  blocks.map(b => b.html).join('\n'),
        content_text:  blocks.map(b => b.text).join('\n\n'),
    };
};

// ── Main ───────────────────────────────────────────────────────────────────
(async () => {
    const structure = JSON.parse(
        fs.readFileSync(path.join(__dirname, 'kartra-content.json'), 'utf8')
    );

    // Build flat lesson list with kartra_post_id and url
    const allLessons = [];
    for (const mod of structure) {
        if (mod.kartra_post_id && mod.url) allLessons.push(mod);
        for (const child of mod.children || []) {
            if (child.kartra_post_id && child.url) allLessons.push(child);
        }
    }

    const browser = await chromium.launch({ headless: true });
    const ctx     = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        viewport:  { width: 1280, height: 900 },
    });

    const page = await ctx.newPage();

    // Login
    console.log('Logging in…');
    await page.goto(PORTAL_URL, { waitUntil: 'networkidle', timeout: 60000 });
    await sleep(2000);
    const passEl = await page.$('input#password') || await page.$('input[type="password"]');
    if (passEl) {
        const emailEl = await page.$('input#username') || await page.$('input[type="text"]');
        if (emailEl) { await emailEl.click({ clickCount: 3 }); await emailEl.fill(EMAIL); }
        await passEl.click(); await passEl.fill(PASSWORD);
        const sub = await page.$('button[type="submit"], input[type="submit"]');
        if (sub) await sub.click(); else await passEl.press('Enter');
        await Promise.race([page.waitForURL('**/index**', { timeout: 30000 }), sleep(15000)]).catch(() => {});
        await sleep(3000);
        console.log('Logged in. URL:', page.url());
    }

    const results = [];
    let filesFound = 0;
    let contentFound = 0;

    console.log(`\nScraping ${allLessons.length} lesson pages for content + files…\n`);

    for (let i = 0; i < allLessons.length; i++) {
        const lesson = allLessons[i];
        process.stdout.write(`[${i + 1}/${allLessons.length}] ${(lesson.title || '').substring(0, 50).padEnd(52)} `);

        try {
            await page.goto(lesson.url, { waitUntil: 'networkidle', timeout: 60000 });
            await sleep(1500);

            const extracted = await page.evaluate(EXTRACT_CONTENT);

            results.push({
                kartra_id:    lesson.kartra_post_id,
                kartra_title: lesson.title,
                page_title:   extracted.page_title,
                content_text: extracted.content_text,
                content_html: extracted.content_html,
                files:        extracted.files,
            });

            filesFound   += extracted.files.length;
            if (extracted.content_text.length > 20) contentFound++;

            const fStr = extracted.files.length > 0 ? ` [${extracted.files.length} files: ${extracted.files.map(f=>f.display_name).join(', ').substring(0,50)}]` : '';
            const cLen = extracted.content_text.length;
            console.log(`content=${cLen}ch${fStr}`);

        } catch (err) {
            console.log(`ERROR: ${err.message.substring(0, 60)}`);
            results.push({
                kartra_id:    lesson.kartra_post_id,
                kartra_title: lesson.title,
                page_title:   null,
                content_text: null,
                content_html: null,
                files:        [],
                error:        err.message,
            });
        }

        // Save progress every 20 lessons
        if ((i + 1) % 20 === 0) {
            fs.writeFileSync(OUT_FILE, JSON.stringify(results, null, 2));
            console.log(`[saved progress: ${i + 1}/${allLessons.length}]`);
        }
    }

    await browser.close();

    fs.writeFileSync(OUT_FILE, JSON.stringify(results, null, 2));

    console.log(`\n=== DONE ===`);
    console.log(`Lessons scraped  : ${results.length}`);
    console.log(`With content     : ${contentFound}`);
    console.log(`Total file links : ${filesFound}`);
    console.log(`Output           : ${OUT_FILE}`);

    process.exit(0);
})();
