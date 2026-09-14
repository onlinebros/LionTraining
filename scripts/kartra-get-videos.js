/**
 * Visits each lesson page in kartra-structure.json and captures the video URL
 * from network traffic (CloudFront CDN, Vimeo, etc.).
 *
 * Writes kartra-content.json  ready for:
 *   php artisan kartra:import --json=scripts/kartra-content.json
 */

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const PORTAL_URL = 'https://besafe.kartra.com/portal/Lion';
const EMAIL      = 'john@ihub.global';
const PASSWORD   = 'peZMDgQs';

const STRUCTURE_FILE = path.join(__dirname, 'kartra-structure.json');
const OUT_FILE       = path.join(__dirname, 'kartra-content.json');

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function isVideoUrl(url) {
    return (
        /\.(mp4|m3u8|webm)(\?|$)/i.test(url) ||
        (/cloudfront\.net/i.test(url) && /\.(mp4|m3u8|webm)/i.test(url))
    ) && !url.includes('.jpg') && !url.includes('.png') && !url.includes('.gif') && !url.includes('.svg');
}

function isThumbnailUrl(url) {
    return /\.(jpg|jpeg|png|gif|webp|svg)/i.test(url);
}

(async () => {
    const structure = JSON.parse(fs.readFileSync(STRUCTURE_FILE, 'utf8'));

    const browser = await chromium.launch({ headless: true });
    const ctx     = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        viewport:  { width: 1280, height: 900 },
    });

    const page = await ctx.newPage();

    // ── Login ───────────────────────────────────────────────────────────────
    console.log('Navigating to portal…');
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
        await sleep(3000);
        console.log('Logged in. URL:', page.url());
    }

    // ── Build flat lesson list ──────────────────────────────────────────────
    const allLessons = [];
    for (const mod of structure) {
        if (mod.type === 'lesson') {
            allLessons.push(mod);
        }
        for (const child of mod.children || []) {
            allLessons.push(child);
        }
    }

    console.log(`\nTotal lessons to visit: ${allLessons.length}`);

    // ── Visit each lesson ───────────────────────────────────────────────────
    for (let i = 0; i < allLessons.length; i++) {
        const lesson = allLessons[i];
        if (!lesson.url) continue;

        process.stdout.write(`[${i + 1}/${allLessons.length}] ${(lesson.title || 'Untitled').substring(0, 50).padEnd(52)} `);

        const captured = [];
        const capturedThumbs = [];

        const handler = async (response) => {
            const url = response.url();
            if (response.status() < 400) {
                if (isVideoUrl(url)) captured.push(url);
                else if (isThumbnailUrl(url) && url.includes('cloudfront')) capturedThumbs.push(url);
            }
        };
        page.on('response', handler);

        try {
            await page.goto(lesson.url, { waitUntil: 'networkidle', timeout: 45000 });
            await sleep(1500);

            // Trigger video load by scrolling
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
            await sleep(1000);

            // Check for video elements / iframes in DOM
            const domVideo = await page.evaluate(() => {
                const iframe = document.querySelector('iframe[src*="vimeo"], iframe[src*="youtube"], iframe[src*="wistia"], iframe[src*="player"]');
                if (iframe) return iframe.src || iframe.getAttribute('src');
                const video = document.querySelector('video[src]');
                if (video) return video.src;
                const source = document.querySelector('video source[src]');
                if (source) return source.src;
                return null;
            });

            if (captured.length > 0) {
                lesson.video_url       = captured[0];
                lesson.thumbnail_url   = capturedThumbs[0] || null;
                if (captured.length > 1) lesson.extra_video_urls = captured.slice(1);
                console.log(`✓ CDN: ${captured[0].split('/').pop().substring(0, 60)}`);
            } else if (domVideo) {
                lesson.video_url = domVideo;
                console.log(`✓ DOM: ${domVideo.substring(0, 60)}`);
            } else {
                // Try regex in HTML
                const html = await page.content();
                const cdnM = html.match(/https?:\/\/[^"'\s]*cloudfront\.net[^"'\s]*\.mp4[^"'\s]*/i) ||
                             html.match(/https?:\/\/[^"'\s]*(?:wistia|vimeo|jwplayer)[^"'\s]*/i) ||
                             html.match(/https?:\/\/[^"'\s]*\.mp4[^"'\s]*/i);
                if (cdnM) {
                    lesson.video_url = cdnM[0].replace(/['"\\>]+$/, '');
                    console.log(`✓ HTML: ${lesson.video_url.substring(0, 60)}`);
                } else {
                    lesson.video_url = null;
                    console.log('— no video');
                }
            }
        } catch (err) {
            lesson.video_url = null;
            console.log(`✗ error: ${err.message.substring(0, 60)}`);
        }

        page.off('response', handler);

        // Save progress every 10 lessons
        if ((i + 1) % 10 === 0) {
            fs.writeFileSync(OUT_FILE, JSON.stringify(structure, null, 2));
            console.log(`[progress] saved after ${i + 1} lessons`);
        }
    }

    await browser.close();

    // ── Final save ──────────────────────────────────────────────────────────
    fs.writeFileSync(OUT_FILE, JSON.stringify(structure, null, 2));

    const withVideo = allLessons.filter(l => l.video_url).length;
    console.log(`\n=== DONE ===`);
    console.log(`Lessons with video : ${withVideo}/${allLessons.length}`);
    console.log(`Output             : ${OUT_FILE}`);

    // Write simple video list
    const videoList = allLessons
        .filter(l => l.video_url)
        .map(l => ({ title: l.title, url: l.video_url, post_id: l.kartra_post_id }));
    fs.writeFileSync(path.join(__dirname, 'kartra-videos.json'), JSON.stringify(videoList, null, 2));
    console.log(`Video list         : scripts/kartra-videos.json`);

    process.exit(0);
})();
