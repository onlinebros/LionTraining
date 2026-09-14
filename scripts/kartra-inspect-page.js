/**
 * Inspect a single lesson page to understand content structure.
 * Run: node kartra-inspect-page.js <post-id>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORTAL_URL = 'https://besafe.kartra.com/portal/Lion';
const EMAIL   = 'john@ihub.global';
const PASSWORD = 'peZMDgQs';
const POST_ID  = process.argv[2] || '30';

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
    const browser = await chromium.launch({ headless: true });
    const ctx = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        viewport: { width: 1280, height: 900 },
    });

    const page = await ctx.newPage();
    const networkFiles = [];
    page.on('response', async r => {
        const url = r.url();
        const ct = r.headers()['content-type'] || '';
        if (/\.(pdf|doc|docx|ppt|pptx|xls|xlsx|zip|mp3|wav)(\?|$)/i.test(url) ||
            ct.includes('pdf') || ct.includes('msword') || ct.includes('presentation')) {
            networkFiles.push({ url, ct, status: r.status() });
        }
    });

    // Login
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
    }

    const url = `${PORTAL_URL}/post/${POST_ID}`;
    console.log('Visiting:', url);
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
    await sleep(3000);

    // Extract structured content from the page
    const content = await page.evaluate(() => {
        // Get all text blocks
        const textBlocks = [];
        document.querySelectorAll('p, h1, h2, h3, h4, h5, li, blockquote').forEach(el => {
            const t = el.textContent.trim();
            if (t && t.length > 10) textBlocks.push({ tag: el.tagName, text: t });
        });

        // Get all links (potential file downloads)
        const links = [];
        document.querySelectorAll('a[href]').forEach(a => {
            links.push({
                href: a.href,
                text: a.textContent.trim().substring(0, 100),
                classes: a.className,
            });
        });

        // Get all images
        const images = [];
        document.querySelectorAll('img[src]').forEach(img => {
            images.push({ src: img.src, alt: img.alt });
        });

        // Get iframes
        const iframes = [];
        document.querySelectorAll('iframe').forEach(f => {
            iframes.push({ src: f.src, title: f.title });
        });

        // Get main content area HTML
        const contentArea = document.querySelector(
            '.membership-post-content, .post-content, .kl-content, .lesson-content, main, article, #content, .content'
        );

        return {
            title: document.title,
            url: window.location.href,
            text_blocks: textBlocks.slice(0, 50),
            links: links.filter(l => l.href && l.href.startsWith('http')),
            images: images.slice(0, 20),
            iframes,
            content_html: contentArea ? contentArea.innerHTML.substring(0, 5000) : null,
        };
    });

    console.log('\n=== PAGE TITLE ===');
    console.log(content.title);

    console.log('\n=== TEXT BLOCKS ===');
    content.text_blocks.forEach(b => console.log(`<${b.tag}> ${b.text.substring(0,120)}`));

    console.log('\n=== LINKS ===');
    content.links.forEach(l => console.log(`  [${l.text.substring(0,60)}] → ${l.href.substring(0,100)}`));

    console.log('\n=== IMAGES ===');
    content.images.forEach(i => console.log(`  [${i.alt}] → ${i.src.substring(0,100)}`));

    console.log('\n=== IFRAMES ===');
    content.iframes.forEach(f => console.log(`  [${f.title}] → ${f.src.substring(0,100)}`));

    console.log('\n=== NETWORK FILES ===');
    networkFiles.forEach(f => console.log(`  ${f.status} ${f.ct.substring(0,30)} → ${f.url.substring(0,100)}`));

    console.log('\n=== CONTENT HTML SNIPPET ===');
    if (content.content_html) console.log(content.content_html.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').substring(0,500));

    // Save full HTML
    const html = await page.content();
    fs.writeFileSync(path.join(__dirname, `lesson-${POST_ID}.html`), html);
    console.log(`\nFull HTML saved to scripts/lesson-${POST_ID}.html`);

    await browser.close();
    process.exit(0);
})();
