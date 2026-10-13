const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async ({ base, runPhp, connection, database }) => {
    const { chromium } = require(process.env.QUEUE_TEST_PLAYWRIGHT_MODULE);
    const browser = await chromium.launch({ channel:process.env.QUEUE_TEST_BROWSER_CHANNEL || 'chrome', headless:true });
    const context = await browser.newContext({ viewport:{ width:1440, height:1000 }, ignoreHTTPSErrors:true });
    if (process.env.QUEUE_TEST_CDN_DIR) {
        await context.route('https://cdn.tailwindcss.com/**', route => route.fulfill({ path:path.join(process.env.QUEUE_TEST_CDN_DIR, 'tailwind.js'), contentType:'application/javascript' }));
        await context.route('https://cdn.jsdelivr.net/npm/sweetalert2@11', route => route.fulfill({ path:path.join(process.env.QUEUE_TEST_CDN_DIR, 'sweetalert.js'), contentType:'application/javascript' }));
    }
    const page = await context.newPage();
    const errors = [];
    const failedRequests = [];
    page.on('requestfailed', request => failedRequests.push(`${request.url()}: ${request.failure()?.errorText}`));
    page.on('pageerror', error => errors.push(error.message));
    await context.addInitScript(() => {
        localStorage.setItem('selectedLocation', JSON.stringify({ location_code:'05', location:'คลัง A' }));
        localStorage.setItem('soundEnabled', 'false');
    });
    const login = (role, all = 0) => context.request.get(`${base}/__login?role=${role}&all=${all}`);
    const capture = async (filename) => {
        if (!process.env.QUEUE_TEST_SCREENSHOT_DIR) return;
        fs.mkdirSync(process.env.QUEUE_TEST_SCREENSHOT_DIR, { recursive:true });
        await page.screenshot({ path:path.join(process.env.QUEUE_TEST_SCREENSHOT_DIR, filename), fullPage:true, animations:'disabled' });
    };
    try {
        await login('admin');
        await page.goto(`${base}/index.html`);
        await page.locator('#deletedItemsLink').waitFor({ state:'visible' });
        await page.waitForFunction(() => getComputedStyle(document.getElementById('deletedItemsLink')).backgroundColor === 'rgb(0, 0, 0)', null, { timeout:10000 }).catch(error => {
            throw new Error(`Black button styles did not load: ${failedRequests.join('; ')}; ${errors.join('; ')}; ${error.message}`);
        });
        assert.equal(await page.locator('#deletedItemsLink').innerText(), 'รายการที่ลบ');
        assert.equal(await page.locator('#deletedItemsLink').getAttribute('href'), 'deleted_items.php?location_code=05');
        assert.equal(await page.locator('#deletedItemsLink').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(0, 0, 0)', 'admin archive button is black');
        await page.locator('#deletedItemsLink').click();
        await page.locator('#tableBody tr').first().waitFor();
        assert.equal(await page.locator('#locationSelect').inputValue(), '05');
        assert.equal(await page.locator('#tableBody tr').count(), 1, 'warehouse context carries to archived items');
        await page.locator('#clearBtn').click();
        await page.waitForFunction(() => document.querySelectorAll('#tableBody tr').length === 2);
        await capture('deleted-items-desktop.png');
        await page.getByRole('button', { name:'ดูรายละเอียด HTTP-A', exact:true }).click();
        await page.getByRole('dialog').waitFor();
        assert.ok((await page.getByRole('dialog').innerText()).includes('บันทึกเดิม'));
        assert.ok((await page.getByRole('dialog').innerText()).includes('12.3400'));
        await capture('deleted-items-details.png');
        await page.setViewportSize({ width:390, height:844 });
        await capture('deleted-items-details-mobile.png');
        await page.getByRole('button', { name:'ปิด', exact:true }).click();
        await page.getByRole('dialog').waitFor({ state:'hidden' });
        await capture('deleted-items-mobile.png');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mobile overflow stays inside the table');
        await page.locator('#searchInput').fill('ไม่พบรายการนี้');
        await page.locator('#searchBtn').click();
        await page.waitForFunction(() => document.getElementById('tableStatus').textContent.startsWith('ไม่พบรายการ'));
        assert.equal(await page.locator('#archiveTable').isVisible(), false, 'empty result hides old rows');
        // Two source rows share the old identity but have different sub_id values.
        runPhp(connection + `$c->select_db('${database}'); $c->query("INSERT INTO transfer_data_from_mssql
            (docno,sub_id,cd_code,location_code,Lname_unit,UNITPRICE,user_lname,delivery_status,custname,cd_name,location,qty,docdate)
            VALUES ('UI-D','4','SKU','05','ชิ้น',12.3400,'เจ้าของ A','ยกเลิก','ลูกค้า D','สินค้า D','คลัง A',2,CURDATE()),
                   ('UI-D','5','SKU','05','ชิ้น',12.3400,'เจ้าของ A','ยกเลิก','ลูกค้า D','สินค้า D','คลัง A',2,CURDATE())");
            $c->query("UPDATE transfer_data_from_mssql SET docdate=CURDATE() WHERE docno='HTTP-C'");`);
        await page.goto(`${base}/my_bills.html`);
        await page.locator('#billsTable').waitFor({ state:'visible' });
        assert.equal(await page.locator('.item-checkbox:not(:disabled)').count(), 3, 'all visible statuses can be selected');
        const receivedCheckbox = page.locator('tr[data-docno="HTTP-C"] .item-checkbox');
        await receivedCheckbox.check();
        assert.equal(await page.locator('#selectedCount').innerText(), '1');
        assert.equal(await page.locator('#deleteSelectedBtn').isDisabled(), true, 'selection does not allow deleting received rows');
        assert.ok((await page.locator('#selectionHint').innerText()).includes('ลบได้เฉพาะ'));
        await receivedCheckbox.uncheck();
        const cancelledCheckbox = page.locator('tr[data-docno="UI-D"] .item-checkbox').first();
        await cancelledCheckbox.check();
        assert.equal(await page.locator('#selectedCount').innerText(), '1', 'individual cancelled row selection works');
        assert.equal(await page.locator('#deleteSelectedBtn').isEnabled(), true);
        await page.waitForResponse(response => response.url().includes('fetch_my_bills.php'), { timeout:15000 });
        assert.equal(await cancelledCheckbox.isChecked(), true, 'background refresh preserves selection');
        await page.locator('#clearSelectionBtn').click();
        await page.locator('#selectAllVisibleItems').check();
        assert.equal(await page.locator('#selectedCount').innerText(), '3', 'select all includes every status');
        assert.equal(await page.locator('#deleteSelectedBtn').isDisabled(), true, 'mixed-status selection cannot be deleted');
        await receivedCheckbox.uncheck();
        assert.equal(await page.locator('#selectedCount').innerText(), '2', 'different source ids remain separate in batch selection');
        await page.locator('#deleteSelectedBtn').click();
        await page.getByRole('button', { name:'ลบรายการ', exact:true }).click();
        await page.waitForFunction(() => document.querySelector('.swal2-html-container')?.textContent.includes('ลบแล้ว 2 รายการ'));
        await page.getByRole('button', { name:'OK', exact:true }).click();
        await page.waitForFunction(() => document.querySelectorAll('.item-checkbox').length === 1);
        await page.setViewportSize({ width:1440, height:1000 });
        await login('user', 1);
        await page.goto(`${base}/index.html`);
        await page.locator('#currentUserPanel').waitFor({ state:'visible' });
        assert.equal(await page.locator('#deletedItemsLink').isVisible(), false, 'ordinary users do not see the archive button');
        assert.equal((await context.request.get(`${base}/deleted_items.php`)).status(), 403);
        await page.goto(`${base}/my_bills.html`);
        await page.locator('#billsTable').waitFor({ state:'visible' });
        assert.equal(await page.locator('#deletedItemsLink').isVisible(), false, 'view-all-bills users do not see the archive button');
        assert.deepEqual(errors, [], 'changed browser flows have no JavaScript errors');
        console.log('DELETED_ITEMS_BROWSER_OK: black admin button, filters, detail modal, mobile, batch identity and user access');
    } finally {
        await context.close();
        await browser.close();
    }
};
