// Run with Node's test runner; uses the existing Playwright runtime, no application dependency.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

test('campaign dialog preferences, native accessibility and responsive ticker', async () => {
    const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'fixtures/campaign.php')], { encoding: 'utf8' });
    const files = {
        '/popup.js': 'assets/controllers/campaign_popup_controller.js',
        '/rows.js': 'assets/controllers/home_section_form_controller.js',
        '/stimulus.js': 'assets/vendor/@hotwired/stimulus/stimulus.index.js',
        '/storefront.css': 'assets/styles/storefront.css',
    };
    const server = http.createServer((req, res) => {
        if (req.url !== '/' && !files[req.url]) { res.writeHead(404); res.end(); return; }
        res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript' : req.url.endsWith('.css') ? 'text/css' : 'text/html');
        res.end(files[req.url] ? fs.readFileSync(path.join(root, files[req.url])) : html);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ headless: true, channel: process.env.BROWSER_CHANNEL || 'chrome' });
    try {
        const context = await browser.newContext({ viewport: { width: 320, height: 568 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(() => {
            const show = HTMLDialogElement.prototype.showModal;
            HTMLDialogElement.prototype.showModal = function() { window.openedAt = performance.now(); return show.call(this); };
        });
        const url = `http://127.0.0.1:${server.address().port}`;
        await page.goto(url);
        await page.waitForFunction(() => document.querySelector('dialog').open);
        assert.equal(await page.evaluate(() => window.openedAt - window.connectedAt >= 145), true, 'delay respected');
        assert.equal(await page.locator('dialog button').evaluate(e => e === document.activeElement), true);
        assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('dialog').evaluate(e => e.scrollHeight > e.clientHeight), true, 'long mobile content scrolls');
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => !document.querySelector('dialog').open);
        assert.equal(await page.locator('#opener').evaluate(e => e === document.activeElement), true);
        assert.equal(await page.evaluate(() => document.body.style.overflow), '');
        await page.locator('.cms-row').first().getByText('Aşağı taşı').click();
        assert.equal(await page.locator('textarea[name="items_0_text"]').inputValue(), 'İade');
        assert.equal(await page.locator('textarea[name="items_1_text"]').inputValue(), 'Kargo');
        await page.getByText('Satır ekle', { exact: true }).click();
        assert.equal(await page.locator('.cms-row').count(), 3);
        await page.locator('textarea[name="items_2_text"]').fill('Güvenli alışveriş');
        await page.locator('.cms-row').last().getByText('Yukarı taşı').click();
        assert.equal(await page.locator('textarea[name="items_1_text"]').inputValue(), 'Güvenli alışveriş');
        await page.locator('.cms-row').last().getByText('Satırı sil').click();
        assert.equal(await page.locator('.cms-row').count(), 2);
        await page.reload();
        await page.waitForTimeout(250);
        assert.equal(await page.locator('dialog').evaluate(e => e.open), false, 'same session dismissed');

        await page.evaluate(() => sessionStorage.clear());
        await page.reload();
        await page.waitForFunction(() => document.querySelector('dialog').open);
        await page.locator('dialog input').check();
        await page.locator('dialog button').click();
        await page.waitForFunction(() => !document.querySelector('dialog').open);
        await page.evaluate(() => sessionStorage.clear());
        await page.reload();
        await page.waitForTimeout(250);
        assert.equal(await page.locator('dialog').evaluate(e => e.open), false, 'persistent preference respected');
        await page.evaluate(() => {
            const dialog = document.querySelector('dialog');
            dialog.dataset.campaignPopupKeyValue = 'test-campaign-2';
        });
        await page.evaluate(() => window.app.getControllerForElementAndIdentifier(document.querySelector('dialog'), 'campaign-popup').open());
        assert.equal(await page.locator('dialog').evaluate(e => e.open), true, 'changed campaign is shown');
        await page.mouse.click(2, 2);
        await page.waitForFunction(() => !document.querySelector('dialog').open);

        for (const width of [320, 680, 1440]) {
            await page.setViewportSize({ width, height: 800 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            const geometry = await page.locator('.notice-ticker-track').evaluate(e => {
                const groups = [...e.children].map(g => g.getBoundingClientRect());
                return { equal: Math.abs(groups[0].width - groups[1].width) < .01, adjacent: Math.abs(groups[0].right - groups[1].left) < 1 };
            });
            assert.deepEqual(geometry, { equal: true, adjacent: true });
        }
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await page.locator('.notice-ticker-track').evaluate(e => getComputedStyle(e).animationName), 'none');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
});
