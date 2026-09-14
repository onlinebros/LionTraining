/**
 * Kartra Portal Scraper v2 — Playwright headless browser.
 * Logs in, navigates each module/lesson, captures CloudFront video URLs
 * from network traffic, and outputs kartra-content.json.
 *
 * Usage:
 *   node kartra-scraper.js
 *   node kartra-scraper.js --headful      # visible browser for debugging
 */

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const PORTAL_URL = 'https://besafe.kartra.com/portal/Lion';
const EMAIL      = 'john@ihub.global';
const PASSWORD   = 'peZMDgQs';
const OUT_FILE   = path.join(__dirname, 'kartra-content.json');

const HEADFUL = process.argv.includes('--headful');

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function isVideoUrl(url) {
    return /\.(mp4|m3u8|webm)(\?|$)/i.test(url) ||
           /cloudfront\.net.*\.(mp4|m3u8|webm)/i.test(url) ||
           /wistia\.com.*medias/i.test(url);
}

// ── Main ───────────────────────────────────────────────────────────────────
(async () => {
    const browser = await chromium.launch({ headless: !HEADFUL });
    const ctx     = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        viewport:  { width: 1280, height: 900 },
    });

    const page = await ctx.newPage();

    // Capture video URLs from all network activity on the main page
    const capturedVideos = {}; // url → page url at time of capture
    page.on('response', async (response) => {
        const url = response.url();
        if (isVideoUrl(url) && response.status() < 400) {
            capturedVideos[url] = page.url();
            console.log('[net] video:', url);
        }
    });

    // ── Login ───────────────────────────────────────────────────────────────
    console.log('→ Opening portal:', PORTAL_URL);
    await page.goto(PORTAL_URL, { waitUntil: 'networkidle', timeout: 60000 });
    await sleep(2000);

    const passVisible = await page.$eval('input[type="password"]', el => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    }).catch(() => false);

    if (passVisible) {
        console.log('→ Logging in…');
        const emailEl = await page.$('input#username') || await page.$('input[name="member_email"]') || await page.$('input[type="text"]:visible');
        const passEl  = await page.$('input#password')  || await page.$('input[name="member_password"]') || await page.$('input[type="password"]');

        if (emailEl) { await emailEl.click({ clickCount: 3 }); await emailEl.fill(EMAIL); }
        if (passEl)  { await passEl.click();                   await passEl.fill(PASSWORD); }

        const submit = await page.$('button[type="submit"], input[type="submit"]');
        if (submit) await submit.click(); else await passEl?.press('Enter');

        await Promise.race([
            page.waitForURL('**/portal/**index**', { timeout: 30000 }),
            sleep(15000),
        ]).catch(() => {});
        await sleep(3000);
        console.log('→ Post-login URL:', page.url());
    } else {
        console.log('→ No login form — already authenticated or redirected.');
    }

    // ── Collect module links from the portal index ──────────────────────────
    console.log('\n→ Collecting module links…');
    await sleep(2000);

    // Get all unique href links that look like portal lesson/module pages
    const allLinks = await page.$$eval('a[href]', els =>
        [...new Set(
            els.map(a => ({ href: a.href.split('?')[0], text: (a.textContent || '').trim() }))
               .filter(l => l.href.includes('/portal/') && l.text && l.text.length > 2)
               .map(l => JSON.stringify(l))
        )].map(s => JSON.parse(s))
    );

    console.log(`Found ${allLinks.length} portal links.`);

    // Separate module-level vs lesson-level links
    // Kartra pattern: /portal/Lion/category/MODULE-SLUG/page/LESSON-SLUG
    const moduleLinks = {};
    const lessonLinks = [];

    for (const link of allLinks) {
        const url = link.href;
        // Match lesson URLs: contain /page/ or /lesson/
        if (/\/page\/|\/lesson\/|\/content\//.test(url)) {
            lessonLinks.push(link);
        } else if (/\/category\//.test(url)) {
            // Module/category index pages
            const slug = url.match(/\/category\/([^/]+)/)?.[1] || url;
            if (!moduleLinks[slug]) {
                moduleLinks[slug] = { href: url, text: link.text };
            }
        }
    }

    console.log(`Module links: ${Object.keys(moduleLinks).length}`);
    console.log(`Lesson links: ${lessonLinks.length}`);

    // If no module/lesson URL pattern found, grab all portal sub-links as lessons
    if (lessonLinks.length === 0 && Object.keys(moduleLinks).length === 0) {
        console.log('→ No structured links — treating all portal links as lessons.');
        for (const link of allLinks) {
            if (link.href !== PORTAL_URL && !link.href.endsWith('/index')) {
                lessonLinks.push(link);
            }
        }
    }

    // ── Build content structure ─────────────────────────────────────────────
    const modules = [];

    // Strategy A: we have module pages → navigate to each to get lessons
    if (Object.keys(moduleLinks).length > 0) {
        const moduleArr = Object.values(moduleLinks);
        for (let mi = 0; mi < moduleArr.length; mi++) {
            const mod = moduleArr[mi];
            console.log(`\n[${mi + 1}/${moduleArr.length}] Module: ${mod.text}`);

            const modObj = { type: 'module', title: mod.text, url: mod.href, order: mi, children: [] };

            try {
                await page.goto(mod.href, { waitUntil: 'networkidle', timeout: 45000 });
                await sleep(2000);

                const lessonLinksOnPage = await page.$$eval('a[href]', els =>
                    [...new Set(
                        els.map(a => ({ href: a.href.split('?')[0], text: (a.textContent || '').trim() }))
                           .filter(l => /\/page\/|\/lesson\/|\/content\//.test(l.href) && l.text)
                           .map(l => JSON.stringify(l))
                    )].map(s => JSON.parse(s))
                );

                console.log(`  Lessons: ${lessonLinksOnPage.length}`);
                for (let li = 0; li < lessonLinksOnPage.length; li++) {
                    modObj.children.push({
                        type: 'lesson', title: lessonLinksOnPage[li].text,
                        url: lessonLinksOnPage[li].href, video_url: null, order: li,
                    });
                }
            } catch (err) {
                console.warn(`  ERROR: ${err.message}`);
            }

            modules.push(modObj);
        }
    }

    // Strategy B: flat lesson list (no module structure detected)
    if (modules.length === 0) {
        for (let i = 0; i < lessonLinks.length; i++) {
            modules.push({ type: 'lesson', title: lessonLinks[i].text, url: lessonLinks[i].href, video_url: null, order: i });
        }
    }

    // ── Visit each lesson page to capture video URLs ────────────────────────
    const allLessons = [];
    for (const item of modules) {
        if (item.type === 'lesson') allLessons.push(item);
        for (const child of item.children || []) allLessons.push(child);
    }

    console.log(`\n→ Visiting ${allLessons.length} lesson pages to capture videos…`);

    for (let i = 0; i < allLessons.length; i++) {
        const lesson = allLessons[i];
        if (!lesson.url) continue;

        process.stdout.write(`[${i + 1}/${allLessons.length}] ${lesson.title} … `);

        // Track video URLs specifically from this page load
        const thisPageVideos = [];
        const responseHandler = (response) => {
            const url = response.url();
            if (isVideoUrl(url) && response.status() < 400) {
                thisPageVideos.push(url);
            }
        };
        page.on('response', responseHandler);

        try {
            await page.goto(lesson.url, { waitUntil: 'networkidle', timeout: 45000 });
            await sleep(2000);

            // 1. Network-captured video from this page
            if (thisPageVideos.length > 0) {
                lesson.video_url = thisPageVideos[0];
                if (thisPageVideos.length > 1) lesson.extra_video_urls = thisPageVideos.slice(1);
                console.log(`video(net): ${lesson.video_url}`);
                page.off('response', responseHandler);
                continue;
            }

            // 2. iframe src
            const iframeSrc = await page.$eval(
                'iframe[src*="vimeo"], iframe[src*="youtube"], iframe[src*="wistia"], iframe[src*="player"], video source[src]',
                el => el.src || el.getAttribute('src')
            ).catch(() => null);
            if (iframeSrc) {
                lesson.video_url = iframeSrc;
                console.log(`video(iframe): ${iframeSrc}`);
                page.off('response', responseHandler);
                continue;
            }

            // 3. video[src]
            const videoSrc = await page.$eval('video[src]', el => el.src).catch(() => null);
            if (videoSrc) {
                lesson.video_url = videoSrc;
                console.log(`video(tag): ${videoSrc}`);
                page.off('response', responseHandler);
                continue;
            }

            // 4. Regex in HTML
            const html = await page.content();
            const cdnMatch = html.match(/https?:\/\/[^"'\s]*cloudfront\.net[^"'\s]*\.mp4[^"'\s]*/i) ||
                             html.match(/https?:\/\/[^"'\s]*\.mp4[^"'\s]*/i) ||
                             html.match(/https?:\/\/player\.vimeo\.com\/video\/\d+[^"'\s]*/i) ||
                             html.match(/https?:\/\/[^"'\s]*wistia[^"'\s]*/i);
            if (cdnMatch) {
                lesson.video_url = cdnMatch[0].replace(/['"\\]+$/, '');
                console.log(`video(html): ${lesson.video_url}`);
                page.off('response', responseHandler);
                continue;
            }

            console.log('no video');
        } catch (err) {
            console.log(`ERROR: ${err.message}`);
        }

        page.off('response', responseHandler);
    }

    await browser.close();

    // ── Write output ────────────────────────────────────────────────────────
    fs.writeFileSync(OUT_FILE, JSON.stringify(modules, null, 2));

    const videoList = allLessons
        .filter(l => l.video_url)
        .map(l => ({ title: l.title, url: l.video_url }));
    fs.writeFileSync(path.join(__dirname, 'kartra-videos.json'), JSON.stringify(videoList, null, 2));

    console.log('\n=== COMPLETE ===');
    console.log(`Modules  : ${modules.length}`);
    console.log(`Lessons  : ${allLessons.length}`);
    console.log(`With video: ${videoList.length}`);
    console.log(`Output   : ${OUT_FILE}`);

    process.exit(0);
})();
