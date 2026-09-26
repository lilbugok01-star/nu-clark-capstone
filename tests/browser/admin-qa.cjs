const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require('playwright');

// Generate fixtures with ADMIN_QA_FIXTURE_DIR set when running AdminQaRegressionTest.
const fixtures = path.resolve(process.argv[2]);
const publicRoot = path.resolve(__dirname, '../../public');
const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    const fixture = /^\/(file-hunting|budget|payments)\.html$/.exec(pathname);
    const file = fixture ? path.join(fixtures, `${fixture[1]}.html`) : path.resolve(publicRoot, `.${pathname}`);
    if ((!fixture && !file.startsWith(publicRoot + path.sep)) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        response.writeHead(404).end();
        return;
    }
    const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.svg': 'image/svg+xml' };
    response.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
    fs.createReadStream(file).pipe(response);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({ headless: true, ...(process.env.QA_BROWSER_CHANNEL ? { channel: process.env.QA_BROWSER_CHANNEL } : {}) });
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.accept());
        const base = `http://127.0.0.1:${server.address().port}`;
        let writes = 0;
        page.on('request', request => { if (request.method() === 'POST') writes++; });

        for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
            await page.setViewportSize(viewport);
            await page.goto(`${base}/file-hunting.html`, { waitUntil: 'networkidle' });
            const state = () => page.locator('#signatoriesContainer .signatory-row').evaluateAll(rows => rows.map(row => ({
                role: row.querySelector('select').value,
                label: row.querySelector('input[type="text"]').value,
                active: row.querySelector('input[type="checkbox"]').checked,
                names: [...row.querySelectorAll('input, select')].map(input => input.name),
            })));
            const saved = await state();
            await page.locator('#signatoriesContainer input[type="text"]').first().fill('Unsaved label');
            await page.locator('#signatoriesContainer select').first().selectOption('executive_director');
            await page.locator('#signatoriesContainer input[type="checkbox"]').first().uncheck();
            await page.getByRole('button', { name: 'Add Signatory' }).click();
            await page.locator('#signatoriesContainer input[type="text"]').last().fill('Unsaved new step');
            await page.locator('#signatoriesContainer button[title="Remove"]').nth(1).click();
            assert.notDeepEqual(await state(), saved);
            await page.getByRole('button', { name: 'Cancel', exact: true }).click();
            assert.deepEqual(await state(), saved, 'Cancel must restore saved rows, labels, roles and active flags');
            assert.equal(writes, 0, 'Editing and cancelling must not submit changes');
            await page.screenshot({ path: path.join(fixtures, `file-hunting-${viewport.width}.png`), fullPage: true });

            for (const name of ['payments', 'budget']) {
                await page.goto(`${base}/${name}.html`, { waitUntil: 'networkidle' });
                const inputs = page.locator('input[type="number"]');
                for (let i = 0; i < await inputs.count(); i++) {
                    await inputs.nth(i).evaluate(input => { input.value = '10000000000'; });
                    assert.equal(await inputs.nth(i).evaluate(input => input.validity.rangeOverflow), true);
                    await inputs.nth(i).evaluate(input => { input.value = '9043432642'; });
                    assert.equal(await inputs.nth(i).evaluate(input => input.checkValidity()), true);
                }
                if (name === 'payments') {
                    await page.locator('#typeExpense').check();
                    assert.equal(await page.locator('#typeExpense').evaluate(input => new FormData(input.form).get('payment_type')), 'expense');
                }
                await page.screenshot({ path: path.join(fixtures, `${name}-${viewport.width}.png`), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: Cancel restores saved configuration without a POST; payment fields and money limits pass on desktop and mobile.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
