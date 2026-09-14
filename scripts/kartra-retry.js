/**
 * Retry pass for lessons that timed out or returned no video.
 * Uses a 90s page timeout and waits longer for network activity.
 * Updates kartra-content.json in place.
 */

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const PORTAL_URL = 'https://besafe.kartra.com/portal/Lion';
const EMAIL      = 'john@ihub.global';
const PASSWORD   = 'peZMDgQs';
const OUT_FILE   = path.join(__dirname, 'kartra-content.json');

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function isVideoUrl(url) {
    return (
        /\.(mp4|m3u8|webm)(\?|$)/i.test(url) ||
        (/cloudfront\.net/i.test(url) && /\.(mp4|m3u8|webm)/i.test(url))
    ) && !/\.(jpg|jpeg|png|gif|webp|svg)/i.test(url);
}

(async () => {
    const structure = JSON.parse(fs.readFileSync(OUT_FILE, 'utf8'));

    // Collect only lessons with no video_url
    const allLessons = [];
    for (const mod of structure) {
        if (mod.type === 'lesson') allLessons.push(mod);
        for (const child of mod.children || []) allLessons.push(child);
    }
    const noVideo = allLessons.filter(l => !l.video_url && l.url);

    console.log(`Retry pass: ${noVideo.length} lessons without video`);

    if (noVideo.length === 0) {
        console.log('Nothing to retry.');
        process.exit(0);
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
    const passVisible = await page.$eval('input[type="password"]', el => {
        const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0;
    }).catch(() => false);
    if (passVisible) {
        const emailEl = await page.$('input#username') || await page.$('input[type="text"]:visible');
        const passEl  = await page.$('input#password')  || await page.$('input[type="password"]');
        if (emailEl) { await emailEl.click({ clickCount: 3 }); await emailEl.fill(EMAIL); }
        if (passEl)  { await passEl.click(); await passEl.fill(PASSWORD); }
        const submit = await page.$('button[type="submit"], input[type="submit"]');
        if (submit) await submit.click(); else await passEl?.press('Enter');
        await Promise.race([page.waitForURL('**/index**', { timeout: 30000 }), sleep(15000)]).catch(() => {});
        await sleep(3000);
        console.log('Logged in. URL:', page.url());
    }

    let found = 0;
    for (let i = 0; i < noVideo.length; i++) {
        const lesson = noVideo[i];
        process.stdout.write(`[${i + 1}/${noVideo.length}] ${(lesson.title || 'Untitled').substring(0, 50).padEnd(52)} `);

        const captured = [];
        const handler = async (response) => {
            const url = response.url();
            if (response.status() < 400 && isVideoUrl(url)) captured.push(url);
        };
        page.on('response', handler);

        try {
            await page.goto(lesson.url, { waitUntil: 'networkidle', timeout: 90000 });
            await sleep(3000);  // extra wait for lazy-loaded video

            // Trigger video load
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
            await sleep(2000);

            if (captured.length > 0) {
                lesson.video_url = captured[0];
                found++;
                console.log(`✓ CDN: ${captured[0].split('/').pop().substring(0, 60)}`);
            } else {
                // Try DOM
                const domVideo = await page.evaluate(() => {
                    const iframe = document.querySelector('iframe[src*="vimeo"], iframe[src*="youtube"], iframe[src*="wistia"], iframe[src*="player"]');
                    if (iframe) return iframe.src;
                    const video = document.querySelector('video[src]');
                    if (video) return video.src;
                    const source = document.querySelector('video source[src]');
                    if (source) return source.src;
                    return null;
                });
                if (domVideo) {
                    lesson.video_url = domVideo;
                    found++;
                    console.log(`✓ DOM: ${domVideo.substring(0, 60)}`);
                } else {
                    const html = await page.content();
                    const m = html.match(/https?:\/\/[^"'\s]*cloudfront\.net[^"'\s]*\.mp4[^"'\s]*/i) ||
                              html.match(/https?:\/\/[^"'\s]*\.mp4[^"'\s]*/i);
                    if (m) {
                        lesson.video_url = m[0].replace(/['"\\>]+$/, '');
                        found++;
                        console.log(`✓ HTML: ${lesson.video_url.substring(0, 60)}`);
                    } else {
                        console.log('— still no video');
                    }
                }
            }
        } catch (err) {
            console.log(`✗ ${err.message.substring(0, 60)}`);
        }

        page.off('response', handler);
    }

    await browser.close();
    fs.writeFileSync(OUT_FILE, JSON.stringify(structure, null, 2));

    const totalWithVideo = allLessons.filter(l => l.video_url).length;
    console.log(`\n=== RETRY DONE ===`);
    console.log(`Newly found : ${found}`);
    console.log(`Total with video: ${totalWithVideo}/${allLessons.length}`);

    // Update video list
    const videoList = allLessons.filter(l => l.video_url).map(l => ({
        title: l.title, url: l.video_url, post_id: l.kartra_post_id,
    }));
    fs.writeFileSync(path.join(__dirname, 'kartra-videos.json'), JSON.stringify(videoList, null, 2));
    console.log(`Output: ${OUT_FILE}`);

    process.exit(0);
})();
