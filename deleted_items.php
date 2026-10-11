<?php
require_once __DIR__ . '/api/_bootstrap.php';
app_require_login(true);
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
$profile = app_current_db_profile();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการที่ลบ - Queue Order</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        button:focus-visible, a:focus-visible { outline: 2px solid #16a34a; outline-offset: 3px; }
        input::placeholder { color: #64748b; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-[96rem] p-4 sm:p-6 md:p-8">
        <header class="mb-6 rounded-2xl bg-slate-900 p-5 text-white">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-bold md:text-3xl">รายการที่ลบ</h1>
                    <p class="mt-2 text-sm text-slate-200">รายการที่ยกเลิกและถูกลบแล้ว · <?php echo htmlspecialchars($profile['label'] ?? 'ชุดข้อมูลปัจจุบัน', ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <nav class="flex flex-wrap gap-2" aria-label="เมนูหลัก">
                    <a href="index.html" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold hover:bg-white/20">หน้าหลัก</a>
                    <a href="admin.html" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold hover:bg-white/20">หน้าจัดการ</a>
                </nav>
            </div>
        </header>
        <form id="filterForm" class="mb-4 grid gap-4 rounded-2xl bg-white p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto] lg:items-end">
            <div>
                <label for="searchInput" class="mb-1 block text-sm font-medium">ค้นหาเลขบิล / ลูกค้า / สินค้า / ผู้ลบ</label>
                <input id="searchInput" type="search" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="พิมพ์คำค้นหา...">
            </div>
            <div>
                <label for="locationSelect" class="mb-1 block text-sm font-medium">คลัง</label>
                <select id="locationSelect" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5"><option value="">ทุกคลัง</option></select>
            </div>
            <div>
                <label for="startDate" class="mb-1 block text-sm font-medium">วันที่ลบ ตั้งแต่</label>
                <input id="startDate" type="date" class="w-full rounded-xl border border-slate-300 px-3 py-2.5">
            </div>
            <div>
                <label for="endDate" class="mb-1 block text-sm font-medium">วันที่ลบ ถึง</label>
                <input id="endDate" type="date" class="w-full rounded-xl border border-slate-300 px-3 py-2.5">
            </div>
            <div class="flex gap-2">
                <button id="searchBtn" type="submit" class="rounded-xl bg-black px-4 py-2.5 font-semibold text-white hover:bg-slate-800 disabled:opacity-50">ค้นหา</button>
                <button id="clearBtn" type="button" class="rounded-xl bg-slate-100 px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-200">ล้างค่า</button>
            </div>
        </form>
        <section class="overflow-hidden rounded-2xl bg-white" aria-label="รายการที่ลบ">
            <p id="tableStatus" class="p-5 text-sm text-slate-600" role="status" aria-live="polite">กำลังโหลดรายการที่ลบ...</p>
            <div class="overflow-x-auto">
                <table id="archiveTable" class="hidden w-full text-left text-sm">
                    <caption class="sr-only">รายการที่ลบพร้อมเวลาลบและผู้ลบ</caption>
                    <thead class="bg-slate-100 text-slate-700"><tr>
                        <th scope="col" class="whitespace-nowrap px-4 py-3">เลขบิล / วันที่เอกสาร</th>
                        <th scope="col" class="px-4 py-3">ลูกค้า</th>
                        <th scope="col" class="px-4 py-3">สินค้า</th>
                        <th scope="col" class="px-4 py-3 text-right">จำนวน</th>
                        <th scope="col" class="px-4 py-3">คลัง</th>
                        <th scope="col" class="whitespace-nowrap px-4 py-3">วันที่ลบ</th>
                        <th scope="col" class="px-4 py-3">ผู้ลบ</th>
                        <th scope="col" class="px-4 py-3">รายละเอียด</th>
                    </tr></thead>
                    <tbody id="tableBody" class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
            <div id="pagination" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"></div>
        </section>
    </main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('filterForm');
    const searchInput = document.getElementById('searchInput');
    const locationSelect = document.getElementById('locationSelect');
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    const searchBtn = document.getElementById('searchBtn');
    const tableStatus = document.getElementById('tableStatus');
    const archiveTable = document.getElementById('archiveTable');
    const tableBody = document.getElementById('tableBody');
    const pagination = document.getElementById('pagination');
    const initialLocation = new URLSearchParams(location.search).get('location_code') || '';
    if (initialLocation) locationSelect.add(new Option(initialLocation, initialLocation));
    locationSelect.value = initialLocation;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[c]));
    const formatDate = (value, time = false) => {
        if (!value) return '-';
        const date = new Date(String(value).replace(' ', 'T') + (time ? '+07:00' : ''));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleString('th-TH', { timeZone:'Asia/Bangkok', year:'numeric', month:'2-digit', day:'2-digit', ...(time ? { hour:'2-digit', minute:'2-digit' } : {}) });
    };
    const api = async (url) => {
        const response = await fetch(url, { credentials:'same-origin', cache:'no-store' });
        const result = await response.json();
        if (response.status === 401) { window.location.href = 'login.html'; throw new Error('กรุณาเข้าสู่ระบบ'); }
        if (!response.ok || !result.success) throw new Error(result.message || 'โหลดข้อมูลไม่สำเร็จ');
        return result;
    };
    const fieldLabels = {
        docno:'เลขบิล', sub_id:'รหัสรายการ', docdate:'วันที่เอกสาร', custname:'ลูกค้า', cd_code:'รหัสสินค้า',
        cd_name:'สินค้า', qty:'จำนวน', lname_unit:'หน่วย', unitprice:'ราคาต่อหน่วย', netamount:'มูลค่า',
        user_lname:'พนักงานขาย', location_code:'รหัสคลัง', location:'คลัง', delivery_status:'สถานะก่อนลบ',
        delivery_remark:'หมายเหตุสถานะ', shortnote:'Shortnote', remark:'หมายเหตุ', received_by_employee:'ผู้รับสินค้า',
        received_qty_total:'จำนวนรับสะสม', received_count:'จำนวนครั้งที่รับ', last_update:'อัปเดตล่าสุด', create_at:'วันที่เพิ่มรายการ',
        branch:'สาขา', shipflag:'วิธีจัดส่ง', ct_lname:'ประเภทสินค้า', brand:'ยี่ห้อ',
    };
    const showDetails = async (id) => {
        try {
            const result = await api(`./api/get_deleted_item_details.php?id=${encodeURIComponent(id)}`);
            const { item, deletion, receive_history: history } = result.data;
            const fieldsByName = Object.fromEntries(Object.entries(item).map(([key, value]) => [key.toLowerCase(), value]));
            const actor = deletion.deleted_by_name || deletion.deleted_by_username || 'ไม่ได้เข้าสู่ระบบ';
            const fields = Object.entries(item).map(([key, value]) => `<div class="min-w-0"><dt class="text-slate-600">${escapeHtml(fieldLabels[key.toLowerCase()] || key)}</dt><dd class="break-words whitespace-pre-wrap font-semibold text-slate-900">${escapeHtml(value ?? '-')}</dd></div>`).join('');
            const historyHtml = history.length ? `<h3 class="mt-5 mb-2 text-left font-bold">ประวัติรับสินค้า</h3><div class="space-y-2 text-left">${history.map(entry => `<div class="rounded-xl bg-slate-50 p-3 text-sm">${escapeHtml(entry.received_at)} · ${escapeHtml(entry.received_by_employee)} · ${escapeHtml(entry.received_qty)} ${escapeHtml(fieldsByName.lname_unit)}<p class="break-words">${escapeHtml(entry.note || '')}</p></div>`).join('')}</div>` : '';
            await Swal.fire({ title:`รายละเอียด ${item.docno}`, html:`<p class="mb-4 text-left text-sm text-slate-600">ลบเมื่อ ${formatDate(deletion.deleted_at, true)} โดย ${escapeHtml(actor)}</p><dl class="grid grid-cols-1 gap-3 text-left text-sm sm:grid-cols-2">${fields}</dl>${historyHtml}`, width:'56rem', confirmButtonText:'ปิด', confirmButtonColor:'#0f172a' });
        } catch (error) { await Swal.fire('โหลดรายละเอียดไม่สำเร็จ', error.message, 'error'); }
    };
    let requestId = 0;
    const loadItems = async (page = 1) => {
        const currentRequest = ++requestId;
        const params = new URLSearchParams({ page:String(page), limit:'20', search:searchInput.value.trim(), location_code:locationSelect.value, startDate:startDate.value, endDate:endDate.value });
        searchBtn.disabled = true;
        tableStatus.textContent = 'กำลังโหลดรายการที่ลบ...';
        tableStatus.classList.remove('hidden');
        archiveTable.classList.add('hidden');
        pagination.replaceChildren();
        try {
            const result = await api(`./api/fetch_deleted_items.php?${params}`);
            if (currentRequest !== requestId) return;
            const selectedLocation = locationSelect.value;
            const locations = new Map();
            for (const item of result.locations || []) {
                for (const code of item.location_code.split(',').map(s => s.trim()).filter(Boolean)) {
                    if (!locations.has(code)) locations.set(code, item.location || '');
                }
            }
            if (selectedLocation && !locations.has(selectedLocation)) locations.set(selectedLocation, '');
            locationSelect.replaceChildren(new Option('ทุกคลัง', ''));
            for (const [code, name] of locations) locationSelect.add(new Option(`${code}${name ? ` - ${name}` : ''}`, code));
            locationSelect.value = selectedLocation;
            tableBody.innerHTML = result.data.map(item => `<tr class="hover:bg-slate-50">
                <td class="px-4 py-3"><p class="font-semibold">${escapeHtml(item.docno)}</p><p class="whitespace-nowrap text-xs text-slate-600">${formatDate(item.docdate)}</p></td>
                <td class="min-w-[10rem] px-4 py-3">${escapeHtml(item.customer_name || '-')}</td>
                <td class="min-w-[12rem] px-4 py-3">${escapeHtml(item.cd_name || '-')}<p class="text-xs text-slate-600">${escapeHtml(item.cd_code)}</p></td>
                <td class="whitespace-nowrap px-4 py-3 text-right">${Number(item.qty || 0).toLocaleString('th-TH', { maximumFractionDigits:3 })} ${escapeHtml(item.unit)}</td>
                <td class="px-4 py-3">${escapeHtml(item.location_code)}<p class="text-xs text-slate-600">${escapeHtml(item.location || '')}</p></td>
                <td class="whitespace-nowrap px-4 py-3">${formatDate(item.deleted_at, true)}</td>
                <td class="min-w-[9rem] px-4 py-3">${escapeHtml(item.deleted_by_name || item.deleted_by_username || 'ไม่ได้เข้าสู่ระบบ')}</td>
                <td class="px-4 py-3"><button type="button" class="details-btn whitespace-nowrap rounded-xl bg-slate-100 px-3 py-2 font-semibold hover:bg-slate-200" data-id="${escapeHtml(item.id)}" aria-label="ดูรายละเอียด ${escapeHtml(item.docno)}">ดูรายละเอียด</button></td>
            </tr>`).join('');
            if (result.data.length) { tableStatus.classList.add('hidden'); archiveTable.classList.remove('hidden'); }
            else tableStatus.textContent = 'ไม่พบรายการที่ลบตามเงื่อนไข ลองเปลี่ยนคำค้นหาหรือเลือกทุกคลัง';
            const meta = result.pagination;
            const info = document.createElement('p');
            info.className = 'text-sm text-slate-600';
            info.textContent = `ทั้งหมด ${meta.total_items.toLocaleString('th-TH')} รายการ · หน้า ${meta.current_page} / ${Math.max(meta.total_pages, 1)}`;
            const buttons = document.createElement('div');
            buttons.className = 'flex gap-2';
            for (const [label, nextPage, disabled] of [['ก่อนหน้า',meta.current_page-1,meta.current_page<=1], ['ถัดไป',meta.current_page+1,meta.current_page>=meta.total_pages]]) {
                const button = document.createElement('button');
                button.type = 'button'; button.textContent = label; button.disabled = disabled;
                button.className = 'rounded-xl bg-slate-100 px-4 py-2 text-sm font-semibold hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-40';
                button.addEventListener('click', () => loadItems(nextPage)); buttons.append(button);
            }
            pagination.append(info, buttons);
        } catch (error) {
            if (currentRequest === requestId) tableStatus.textContent = error.message;
        } finally {
            if (currentRequest === requestId) searchBtn.disabled = false;
        }
    };
    form.addEventListener('submit', event => { event.preventDefault(); loadItems(1); });
    document.getElementById('clearBtn').addEventListener('click', () => { form.reset(); loadItems(1); });
    tableBody.addEventListener('click', event => { const button = event.target.closest('.details-btn'); if (button) showDetails(button.dataset.id); });
    loadItems(1);
});
</script>
</body>
</html>
