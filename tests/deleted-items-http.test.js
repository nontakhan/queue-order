// Exercise real PHP endpoints against an isolated localhost database and test-only sessions.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.QUEUE_TEST_PHP || 'C:/xampp/php/php.exe';
const dbPort = Number(process.env.QUEUE_TEST_MYSQL_PORT || 3307);
const serverPort = Number(process.env.QUEUE_TEST_HTTP_PORT || 8097);
const database = `codex_queue_archive_http_${Date.now()}`;
const base = `http://127.0.0.1:${serverPort}`;
const runPhp = (source) => {
    const result = spawnSync(php, ['-r', source], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const connection = `$c = new mysqli('127.0.0.1','root','','',${dbPort}); $c->set_charset('utf8mb4');`;

(async () => {
    assert.ok(fs.existsSync(path.join(root, 'api/fetch_deleted_items.php')), 'admin archive listing endpoint is missing');
    const site = fs.mkdtempSync(path.join(os.tmpdir(), 'queue-archive-http-'));
    const sessions = path.join(site, 'sessions');
    fs.mkdirSync(sessions);
    fs.mkdirSync(path.join(site, 'api'));
    for (const file of fs.readdirSync(path.join(root, 'api')).filter(file => file.endsWith('.php'))) {
        fs.copyFileSync(path.join(root, 'api', file), path.join(site, 'api', file));
    }
    for (const file of ['setup_deleted_items.sql', 'deleted_items.php']) fs.copyFileSync(path.join(root, file), path.join(site, file));
    for (const file of fs.readdirSync(root).filter(file => /\.(html|ico|svg|png)$/.test(file))) fs.copyFileSync(path.join(root, file), path.join(site, file));
    fs.writeFileSync(path.join(site, 'db_config.php'), `<?php $db_host='127.0.0.1'; $db_user='root'; $db_pass=''; $db_name='${database}';`);
    // This router lives in the temporary fixture only; it is never served by the app.
    fs.writeFileSync(path.join(site, 'router.php'), `<?php
        ini_set('session.save_path', __DIR__ . '/sessions');
        if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__login') {
            session_start();
            $_SESSION['db_profile']='default';
            if ($_GET['role'] === 'guest') { unset($_SESSION['user']); }
            else { $_SESSION['user']=['id'=>7,'username'=>'fixture','full_name'=>'ผู้ทดสอบ','role'=>$_GET['role'],
                'sales_lname'=>'เจ้าของ A','can_view_all_bills'=>(int)($_GET['all']??0)]; }
            header('Content-Type: application/json'); echo '{"success":true}'; return true;
        } return false;`);
    runPhp(connection + `$c->query('CREATE DATABASE ${database} CHARACTER SET utf8mb4'); $c->select_db('${database}');
        $c->query("CREATE TABLE transfer_data_from_mssql (
            docno VARCHAR(100),sub_id VARCHAR(100),cd_code VARCHAR(100),location_code VARCHAR(100),
            Lname_unit VARCHAR(100),UNITPRICE DECIMAL(15,4),user_lname VARCHAR(255),delivery_status VARCHAR(50),
            custname VARCHAR(255),cd_name VARCHAR(255),location VARCHAR(255),qty DECIMAL(15,3),
            shortnote VARCHAR(500),docdate DATE,PRIMARY KEY(docno,sub_id,cd_code)) ENGINE=InnoDB");
        $c->query("INSERT INTO transfer_data_from_mssql VALUES
            ('HTTP-A','1','SKU','05','ชิ้น',12.3400,'เจ้าของ A','ยกเลิก','ลูกค้าไทย','สินค้าไทย','คลัง A',3,'บันทึกเดิม','2026-10-11'),
            ('HTTP-B','2','SKU','06','ชิ้น',12.3400,'เจ้าของ B','ยกเลิก','ลูกค้า B','สินค้า B','คลัง B',2,NULL,'2026-10-10'),
            ('HTTP-C','3','SKU','05','ชิ้น',12.3400,'เจ้าของ A','รับแล้ว','ลูกค้า C','สินค้า C','คลัง A',2,NULL,'2026-10-10')");
        $c->query("ALTER TABLE transfer_data_from_mssql
            CHANGE custname CUSTNAME VARCHAR(255), CHANGE Lname_unit LName_unit VARCHAR(100), CHANGE docdate DOCDATE DATE,
            ADD NETAMOUNT DECIMAL(15,4) DEFAULT 0, ADD REMARK VARCHAR(500) NULL, ADD branch VARCHAR(100) NULL,
            ADD shipflag INT DEFAULT 0, ADD delivery_remark VARCHAR(500) NULL, ADD received_by_employee VARCHAR(255) NULL,
            ADD last_update DATETIME DEFAULT CURRENT_TIMESTAMP, ADD create_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ADD received_qty_total DECIMAL(15,3) DEFAULT 0, ADD received_count INT DEFAULT 0");
        $c->query("CREATE TABLE app_locations (location_code VARCHAR(100), location_name VARCHAR(255), is_active INT DEFAULT 1)");
        $c->query("INSERT INTO app_locations VALUES ('05','คลัง A',1),('06','คลัง B',1)");
        $c->query("CREATE TABLE notification_sounds (location_code VARCHAR(100),sound_path VARCHAR(255))");`);
    const server = spawn(php, ['-d', `mysqli.default_port=${dbPort}`, '-S', `127.0.0.1:${serverPort}`, '-t', site, path.join(site, 'router.php')], { windowsHide: true, stdio: 'pipe' });
    let logs = '';
    server.stderr.on('data', (chunk) => { logs += chunk; });
    try {
        let ready = false;
        for (let i = 0; i < 50; i++) {
            try { await fetch(`${base}/api/auth_me.php`); ready = true; break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        assert.ok(ready, `fixture server did not start: ${logs}`);
        let cookie = '';
        const login = async (role, all = 0) => {
            const response = await fetch(`${base}/__login?role=${role}&all=${all}`, { headers: { cookie } });
            if (response.headers.get('set-cookie')) cookie = response.headers.get('set-cookie').split(';')[0];
            const loginBody = await response.text();
            assert.equal(loginBody, '{"success":true}', `fixture login failed: ${loginBody}\n${logs}`);
            const me = await (await fetch(`${base}/api/auth_me.php`, { headers: { cookie } })).json();
            assert.equal(me.authenticated, role !== 'guest', `fixture session did not persist (${role}): ${logs}`);
        };
        const request = (url, options = {}) => fetch(base + url, { ...options, headers: { cookie, ...options.headers } });
        const target = { docno:'HTTP-A', sub_id:'1', cd_code:'SKU', location_code:'05', unit:'ชิ้น', price:'12.3400', user_lname:'เจ้าของ A' };
        const remove = (item) => request('/api/delete_item.php', { method:'POST', body:new URLSearchParams({deletion_reason:'แก้ไขรายการ', ...item}) });
        await login('guest');
        for (const url of ['/deleted_items.php', '/api/fetch_deleted_items.php', '/api/get_deleted_item_details.php?id=1']) assert.equal((await request(url)).status, 401, 'guests must not access deleted items');
        await login('user', 1);
        for (const url of ['/deleted_items.php', '/api/fetch_deleted_items.php', '/api/get_deleted_item_details.php?id=1']) assert.equal((await request(url)).status, 403, 'view-all-bills permission does not grant deleted archive access');
        let response = await remove({ ...target, docno:'HTTP-C', sub_id:'3', deletion_reason:'' });
        assert.equal(response.status, 422, 'server requires a deletion reason before removing any status');
        response = await remove(target);
        assert.equal(response.status, 200);
        assert.equal((await response.json()).deleted, 1);
        response = await remove(target);
        assert.equal(response.status, 422, 'repeat deletion must not duplicate the archive');
        await login('admin');
        response = await request('/deleted_items.php');
        assert.equal(response.status, 200);
        assert.match(await response.text(), /รายการที่ลบ/);
        response = await request('/api/fetch_deleted_items.php?search=ลูกค้าไทย');
        let listing = await response.json();
        assert.equal(listing.pagination.total_items, 1, 'Thai customer search finds archived rows');
        assert.equal(listing.data[0].docno, 'HTTP-A');
        assert.equal(listing.data[0].deleted_by_name, 'ผู้ทดสอบ');
        assert.equal(listing.data[0].deletion_reason, 'แก้ไขรายการ');
        assert.equal((await (await request('/api/fetch_deleted_items.php?location_code=06')).json()).pagination.total_items, 0, 'warehouse filters apply');
        assert.equal((await request('/api/fetch_deleted_items.php?startDate=2026-02-30')).status, 422, 'invalid calendar dates are rejected');
        response = await request(`/api/get_deleted_item_details.php?id=${listing.data[0].id}`);
        const details = await response.json();
        assert.equal(details.data.item.shortnote, 'บันทึกเดิม');
        assert.equal(details.data.item.UNITPRICE, '12.3400');
        assert.equal(details.data.deletion.deletion_reason, 'แก้ไขรายการ');
        assert.equal((await request('/api/get_deleted_item_details.php?id=999999')).status, 404);
        assert.equal((await request('/api/get_deleted_item_details.php?id=1 OR 1=1')).status, 422);
        assert.equal((await request('/api/delete_item.php')).status, 405);
        response = await request('/api/delete_my_bills.php', { method:'POST', body:new URLSearchParams({ deletion_reason:'แก้ไขหลายรายการ', items:JSON.stringify([{ ...target,docno:'HTTP-B',sub_id:'2',location_code:'06',user_lname:'เจ้าของ B' }]) }) });
        assert.equal(response.status, 200);
        assert.equal((await response.json()).deleted, 1, 'batch deletion uses the same archive path');
        assert.equal((await (await request('/api/fetch_deleted_items.php?limit=1&page=2')).json()).pagination.current_page, 2);
        assert.equal((await (await request('/api/fetch_deleted_items.php')).json()).pagination.total_items, 2);
        console.log('DELETED_ITEMS_HTTP_OK: archive, filters, details, batch and admin-only access');
        if (process.env.QUEUE_TEST_PLAYWRIGHT_MODULE) {
            await require('./deleted-items-browser.js')({ base, runPhp, connection, database });
        }
    } finally {
        server.kill();
        await new Promise(resolve => server.once('exit', resolve));
        runPhp(connection + `$c->query('DROP DATABASE ${database}');`);
        // site was created exclusively for this test and contains only local fixtures.
        fs.rmSync(site, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
