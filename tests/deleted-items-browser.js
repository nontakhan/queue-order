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
        assert.ok((await page.getByRole('dialog').innerText()).includes('แก้ไขรายการ'));
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
        // Exercise warehouse history deletion for every rendered status and both dialogs.
        for (const [index, status] of ['รับแล้ว', 'เลื่อน', 'รับบางส่วน', 'ยกเลิก', 'รอตรวจรับ'].entries()) {
            const docno = `UI-H-${index}`;
            runPhp(connection + `$c->select_db('${database}'); $c->query("INSERT INTO transfer_data_from_mssql
                (docno,sub_id,cd_code,location_code,Lname_unit,UNITPRICE,user_lname,delivery_status,custname,cd_name,location,qty,docdate)
                VALUES ('${docno}','1','SKU','05','ชิ้น',12.3400,'เจ้าของ A','${status}','ลูกค้าประวัติ','สินค้า','คลัง A',2,CURDATE())");`);
            await page.goto(`${base}/history.html?location=05&status=${encodeURIComponent(status)}`);
            const row = page.locator(`tr[data-docno="${docno}"]`);
            await row.waitFor();
            if (index === 2) {
                await row.locator('.item-select-checkbox').check();
                await page.locator('#bulkDeleteBtn').click();
            } else {
                await row.locator('.delete-btn').click();
            }
            const confirm = page.locator('.swal2-confirm');
            await confirm.click();
            await page.locator('.swal2-validation-message').waitFor({state:'visible'});
            assert.equal(await row.count(), 1, 'history blank reason leaves source visible');
            await page.getByRole('textbox', {name:'เหตุผลการลบ',exact:true}).fill(`เหตุผลประวัติ ${index}`);
            const response = await Promise.all([page.waitForResponse(r => r.url().endsWith('/api/delete_item.php')), confirm.click()]);
            assert.equal((await response[0].json()).success, true);
            await page.waitForFunction(() => document.querySelector('.swal2-title')?.textContent.includes('ลบแล้ว'));
            await page.locator('.swal2-confirm').click();
            await row.waitFor({state:'detached'});
        }
        // All report copies use the shared deletion endpoint and need the reason too.
        for (const [index, [file, customer]] of [
            ['report_namrung_customer.html','นำรุ่งเคหะภัณฑ์สำนักงานใหญ่'],
            ['report_namrung_branch_00001_customer.html','นำรุ่งเคหะภัณฑ์สาขาที่00001'],
            ['report_namrung_thurakit_customer.html','นำรุ่งธกิจสำนักงานใหญ่'],
            ['report_namrung_tool_customer.html','นำรุ่งทูลสำนักงานใหญ่'],
        ].entries()) {
            const docno = `UI-R-${index}`;
            runPhp(connection + `$c->select_db('${database}'); $c->query("INSERT INTO transfer_data_from_mssql
                (docno,sub_id,cd_code,location_code,Lname_unit,UNITPRICE,user_lname,delivery_status,custname,cd_name,location,qty,docdate)
                VALUES ('${docno}','1','SKU','05','ชิ้น',12.3400,'เจ้าของ A','รับบางส่วน','${customer}','สินค้า','คลัง A',2,CURDATE())");`);
            await page.goto(`${base}/${file}`);
            await page.locator('.delete-btn').first().waitFor();
            await page.locator(`.delete-btn[data-docno="${docno}"]`).click();
            await page.getByRole('textbox', {name:'เหตุผลการลบ',exact:true}).fill(`เหตุผลรายงาน ${index}`);
            const response = await Promise.all([page.waitForResponse(r => r.url().endsWith('/api/delete_item.php')), page.locator('.swal2-confirm').click()]);
            assert.equal((await response[0].json()).success, true);
            await page.waitForFunction(() => document.querySelector('.swal2-title')?.textContent.includes('สำเร็จ'));
            await page.locator('.swal2-confirm').click();
            assert.equal(await page.locator(`.delete-btn[data-docno="${docno}"]`).count(), 0);
        }
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
        assert.equal(await page.locator('#deleteSelectedBtn').isEnabled(), true, 'received rows can also be archived');
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
        assert.equal(await page.locator('#deleteSelectedBtn').isEnabled(), true, 'mixed-status selection can be archived');
        assert.equal(await page.locator('#selectedCount').innerText(), '3', 'different source ids remain separate in batch selection');
        await page.locator('#deleteSelectedBtn').click();
        await page.getByRole('button', { name:'ลบรายการ', exact:true }).click();
        await page.locator('.swal2-validation-message').waitFor({state:'visible'});
        assert.equal(await page.locator('.item-checkbox').count(), 3, 'empty reason does not remove rows');
        const reason = 'แก้ไขข้อมูล <img src=x onerror=alert(1)>\nรายการซ้ำ';
        await page.getByRole('textbox', {name:'เหตุผลการลบ',exact:true}).fill(reason);
        await page.getByRole('button', { name:'ลบรายการ', exact:true }).click();
        await page.waitForFunction(() => document.querySelector('.swal2-html-container')?.textContent.includes('ลบแล้ว 3 รายการ'));
        await page.getByRole('button', { name:'OK', exact:true }).click();
        await page.waitForFunction(() => document.querySelectorAll('.item-checkbox').length === 0);
        await page.goto(`${base}/deleted_items.php`);
        await page.locator('#searchInput').fill('รายการซ้ำ');
        await page.locator('#searchBtn').click();
        await page.waitForFunction(() => document.querySelectorAll('#tableBody tr').length === 3);
        assert.ok((await page.locator('#tableBody').innerText()).includes(reason));
        assert.equal(await page.locator('#tableBody img').count(), 0, 'reason HTML is shown as text');
        await page.getByRole('button', {name:'ดูรายละเอียด HTTP-C',exact:true}).click();
        await page.getByRole('dialog').waitFor();
        assert.ok((await page.getByRole('dialog').innerText()).includes(reason));
        assert.equal(await page.locator('#swal2-html-container img').count(), 0);
        await page.getByRole('button', {name:'ปิด',exact:true}).click();
        // Leave one visible row for the ordinary-user access check below.
        runPhp(connection + `$c->select_db('${database}'); $c->query("INSERT INTO transfer_data_from_mssql
            (docno,sub_id,cd_code,location_code,Lname_unit,UNITPRICE,user_lname,delivery_status,custname,cd_name,location,qty,docdate)
            VALUES ('UI-USER','1','SKU','05','ชิ้น',12.3400,'เจ้าของ A','เลื่อน','ลูกค้า','สินค้า','คลัง A',2,CURDATE())");`);
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
