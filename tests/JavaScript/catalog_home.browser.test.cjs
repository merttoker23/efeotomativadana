const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

test('real home template switches panels, empty messages, keyboard focus and ARIA', async () => {
    const html = execFileSync(process.env.PHP_BINARY || 'php', ['tests/JavaScript/fixtures/home_tabs.php'], { encoding: 'utf8' });
    const files = { '/tabs.js': 'assets/controllers/home_tabs_controller.js', '/stimulus.js': 'assets/vendor/@hotwired/stimulus/stimulus.index.js', '/storefront.css': 'assets/styles/storefront.css' };
    const server = http.createServer((req, res) => {
        res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript' : req.url.endsWith('.css') ? 'text/css' : 'text/html; charset=utf-8');
        res.end(files[req.url] ? fs.readFileSync(files[req.url]) : html);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    try {
        const page = await browser.newPage();
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        const tabs = page.getByRole('tab');
        await tabs.nth(1).click();
        async function check(index) {
            assert.equal(await page.getByRole('tabpanel').count(), 1);
            assert.equal(await page.getByRole('tabpanel').innerText(), `Empty source ${index}`);
            assert.equal(await tabs.nth(index).getAttribute('aria-selected'), 'true');
            assert.equal(await tabs.nth(index).getAttribute('tabindex'), '0');
            assert.equal(await tabs.nth(index).evaluate(e => e === document.activeElement), true);
            assert.equal(await page.locator('[role="tabpanel"][hidden]').count(), 3);
        }
        await check(1);
        for (const [key, index] of [['ArrowRight', 2], ['End', 3], ['ArrowRight', 0], ['ArrowLeft', 3], ['Home', 0]]) {
            await page.keyboard.press(key);
            await check(index);
        }
        await page.getByRole('tabpanel').evaluate(e => {
            const button = document.createElement('button');
            button.textContent = 'Sepete ekle';
            e.append(button);
            button.focus();
        });
        await page.keyboard.press('End');
        assert.equal(await page.getByRole('tabpanel').count(), 1);
        assert.equal(await tabs.nth(0).getAttribute('aria-selected'), 'true');
        assert.equal(await page.getByRole('button', { name: 'Sepete ekle' }).evaluate(e => e === document.activeElement), true);
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
});

test('served catalog assets auto-submit localized prices, slider, stock and navigation without dropping filters', async () => {
    let server;
    let base = process.env.CATALOG_BASE_URL;
    if (!base) {
        const files = { '/filters.js': 'assets/controllers/catalog_filters_controller.js', '/range.js': 'assets/controllers/catalog_price_range_controller.js', '/stimulus.js': 'assets/vendor/@hotwired/stimulus/stimulus.index.js' };
        server = http.createServer((req, res) => {
            res.setHeader('Content-Type', files[req.url] ? 'text/javascript' : 'text/html; charset=utf-8');
            res.end(files[req.url] ? fs.readFileSync(files[req.url]) : execFileSync(process.env.PHP_BINARY || 'php', ['tests/JavaScript/fixtures/catalog.php', req.url], { encoding: 'utf8' }));
        });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        base = `http://127.0.0.1:${server.address().port}`;
    }
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${base}/yeni/katalog?sort=price-asc&probe=keep`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        const min = page.locator('[data-catalog-price-range-target="minInput"]');
        assert.equal(await min.getAttribute('type'), 'text');
        assert.equal(await page.getByRole('button', { name: 'Filtreyi Uygula', exact: true }).count(), 0);
        await min.fill('0,01');
        await page.waitForURL(url => url.searchParams.get('min_price') === '0.01');
        assert.equal(new URL(page.url()).searchParams.get('sort'), 'price-asc');
        assert.equal(new URL(page.url()).searchParams.get('probe'), 'keep');
        assert.equal(new URL(page.url()).hash, '#catalog-results');
        await page.waitForFunction(() => document.activeElement?.id === 'catalog-results');
        const max = page.locator('[data-catalog-price-range-target="maxInput"]');
        await max.fill('399.735,00');
        await page.waitForURL(url => url.searchParams.get('max_price') === '399735.00');
        assert.equal(await max.inputValue(), '399.735,00');
        await page.locator('.catalog-filter-form input[type="checkbox"][name="availability"]').check();
        await page.waitForURL(url => url.searchParams.get('availability') === 'in-stock');
        assert.equal(new URL(page.url()).searchParams.get('max_price'), '399735.00');
        const slider = page.locator('[data-catalog-price-range-target="maxRange"]');
        const sliderMin = Number(await page.locator('[data-catalog-price-range-target="minRange"]').inputValue()).toFixed(2);
        const sliderMax = await slider.evaluate(e => {
            e.value = (Number(e.min) + (Number(e.max) - Number(e.min)) * .75).toFixed(2);
            const value = Number(e.value).toFixed(2);
            e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true }));
            return value;
        });
        await page.waitForURL(url => url.searchParams.get('max_price') === sliderMax);
        assert.equal(await max.inputValue(), new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(sliderMax)));
        await page.locator('#catalog-sort').selectOption('price-desc');
        await page.waitForURL(url => url.searchParams.get('sort') === 'price-desc');
        assert.equal(new URL(page.url()).searchParams.get('min_price'), sliderMin);
        assert.equal(new URL(page.url()).searchParams.get('max_price'), sliderMax);
        assert.equal(new URL(page.url()).searchParams.get('probe'), 'keep');
        await page.locator('.catalog-category-list a').first().click();
        await page.waitForURL(url => url.pathname.includes('/kategori/'));
        assert.equal(new URL(page.url()).searchParams.get('availability'), 'in-stock');
        assert.equal(new URL(page.url()).searchParams.get('max_price'), sliderMax);
        assert.equal(new URL(page.url()).searchParams.get('probe'), 'keep');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); if (server) await new Promise(resolve => server.close(resolve)); }
});
