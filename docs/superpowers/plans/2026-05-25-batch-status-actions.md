# Batch Status Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add page-scoped multi-selection on the pending-items table so users can receive all remaining quantities, postpone, or cancel several visible rows in one atomic action.

**Architecture:** Add `api/update_status_batch.php` as a dedicated transactional endpoint, leaving the existing single-row and partial-receive endpoint unchanged. Enhance `index.html` with selection state, a batch action bar, and one-request batch flows that reuse existing employee and postpone dialogs. As this repository has no automated test harness, use PHP lint, endpoint/browser integration verification, and a browser UI smoke pass as the executable verification path.

**Tech Stack:** PHP with mysqli transactions, MySQL/MariaDB, vanilla JavaScript, SweetAlert2, Tailwind utility classes, existing XAMPP-served application.

---

## File Structure

- Create `api/update_status_batch.php`: validate batch requests and execute atomic receive/postpone/cancel transactions.
- Modify `index.html`: render page-scoped checkboxes/action bar, manage selection state, and submit batch actions.
- No schema, fetch API, or history-page changes are required because item identity, cumulative receive fields, and receive history already exist.

### Task 1: Transactional Batch Status API

**Files:**
- Create: `api/update_status_batch.php`
- Reference: `api/update_status.php`
- Reference: `api/_bootstrap.php`

- [ ] **Step 1: Capture the API verification cases before implementation**

Create a temporary manual verification checklist for the endpoint while the file does not yet exist:

```text
POST api/update_status_batch.php with:
- missing/empty items -> JSON failure and no writes
- unsupported new_status -> JSON failure and no writes
- รับแล้ว without received_by_employee -> JSON failure and no writes
- รับแล้ว with two valid rows -> both rows become รับแล้ว and each history receives full remaining quantity
- รับแล้ว with one invalid/fully-received row -> neither row changes
- เลื่อน with two valid rows and one remark -> both rows use the remark
- ยกเลิก with two valid rows -> both rows are cancelled
```

Run before creating the endpoint:

```powershell
Invoke-WebRequest -Method Post -Uri 'http://localhost/queue-order/api/update_status_batch.php' -Body @{ new_status = 'ยกเลิก'; items = '[]' }
```

Expected: request cannot succeed because `api/update_status_batch.php` does not exist yet.

- [ ] **Step 2: Create request validation and item parsing**

Create `api/update_status_batch.php` using the application bootstrap and JSON responses. Define status helpers and parse a non-empty JSON item array into normalized item identities:

```php
<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function batch_status_received(): string { return 'รับแล้ว'; }
function batch_status_postponed(): string { return 'เลื่อน'; }
function batch_status_cancelled(): string { return 'ยกเลิก'; }

function parse_batch_price($value): ?float
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (!is_numeric(str_replace(',', '', $text))) {
        throw new Exception('Invalid item price.');
    }
    return (float) str_replace(',', '', $text);
}

function parse_batch_items(string $json): array
{
    $items = json_decode($json, true);
    if (!is_array($items) || count($items) === 0) {
        throw new Exception('No selected items.');
    }
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item) || trim((string) ($item['docno'] ?? '')) === '' || trim((string) ($item['cd_code'] ?? '')) === '') {
            throw new Exception('Invalid selected item.');
        }
        $normalized[] = [
            'docno' => trim((string) $item['docno']),
            'cd_code' => trim((string) $item['cd_code']),
            'unit' => trim((string) ($item['unit'] ?? '')),
            'price' => parse_batch_price($item['price'] ?? ''),
        ];
    }
    return $normalized;
}
```

Validate `new_status`, `received_by_employee`, and request fields before beginning a transaction:

```php
$status = app_get_post('new_status');
$items = parse_batch_items((string) ($_POST['items'] ?? ''));
$allowed = [batch_status_received(), batch_status_postponed(), batch_status_cancelled()];
if (!in_array($status, $allowed, true)) {
    throw new Exception('Unsupported batch status.');
}
$receivedByEmployee = app_get_post('received_by_employee');
if ($status === batch_status_received() && $receivedByEmployee === '') {
    throw new Exception('Missing receiving employee.');
}
```

- [ ] **Step 3: Implement atomic full-remaining receive**

Inside one transaction, for each parsed identity:

```php
$selectSql = 'SELECT qty, COALESCE(received_qty_total, 0) AS received_qty_total
    FROM transfer_data_from_mssql
    WHERE docno = ? AND cd_code = ?';
if ($hasFullIdentity) {
    $selectSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
}
$selectSql .= ' FOR UPDATE';
```

After loading exactly one row, calculate `$remaining = (float) $row['qty'] - (float) $row['received_qty_total'];` and throw when `$remaining <= 0.0001`. Insert a receive history row with `$remaining`, then update:

```sql
SET delivery_status = 'รับแล้ว',
    delivery_remark = NULL,
    received_by_employee = ?,
    received_qty_total = qty,
    received_count = received_count + 1
```

Use the same optional `unit` and `price` identity clauses as `api/update_status.php`, and verify each update affects exactly one row. Commit only after every selected item succeeds; rollback on any exception.

- [ ] **Step 4: Implement atomic postpone and cancel updates**

For `เลื่อน`, require a non-empty `remark`; for `ยกเลิก`, clear any previous remark. Within the existing transaction, issue one identity-scoped update for each selected item:

```sql
SET delivery_status = ?,
    delivery_remark = ?,
    received_by_employee = NULL,
    received_qty_total = 0,
    received_count = 0
```

For cancel pass `NULL` for `delivery_remark`; for postpone pass the selected shared reason. Throw when any update affects no row so no partial batch is committed.

- [ ] **Step 5: Run PHP and endpoint verification**

Run:

```powershell
php -l api\update_status_batch.php
```

Expected:

```text
No syntax errors detected in api\update_status_batch.php
```

With disposable rows visible in the selected database profile, exercise each checklist case by calling the endpoint from the application/browser network flow or `Invoke-WebRequest`, then reload the pending and history views. Expected: successful actions update every selected row; intentionally failed receive attempts leave all selected rows unchanged.

### Task 2: Page-Scoped Selection UI

**Files:**
- Modify: `index.html`

- [ ] **Step 1: Establish the failing UI check**

Open the pending-items page before modification and verify:

```text
- No checkbox exists in the table header or rows.
- No action bar shows selected item count or batch action buttons.
```

Expected: the proposed controls are absent, demonstrating the new UI has not been implemented.

- [ ] **Step 2: Add static action-bar and header checkbox markup**

Before the table container, add a hidden batch action bar:

```html
<div id="batchActionBar" class="mb-4 hidden items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
    <p id="batchSelectionSummary" class="text-sm font-semibold text-emerald-900">เลือกแล้ว 0 รายการ</p>
    <div class="flex flex-wrap gap-2">
        <button id="batchReceiveBtn" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white">รับทั้งหมด</button>
        <button id="batchPostponeBtn" class="rounded-lg bg-yellow-500 px-4 py-2 text-sm font-semibold text-white">เลื่อน</button>
        <button id="batchCancelBtn" class="rounded-lg bg-red-500 px-4 py-2 text-sm font-semibold text-white">ยกเลิก</button>
        <button id="clearBatchSelectionBtn" class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700">ล้างการเลือก</button>
    </div>
</div>
```

Add the header checkbox column before the existing icon column:

```html
<th class="px-3 py-3 text-center">
    <input id="selectAllVisibleItems" type="checkbox" aria-label="เลือกทุกรายการในหน้านี้">
</th>
```

- [ ] **Step 3: Add batch state and page-scoped rendering**

Add DOM references and an endpoint constant next to the current constants:

```javascript
const batchActionBar = document.getElementById('batchActionBar');
const batchSelectionSummary = document.getElementById('batchSelectionSummary');
const selectAllVisibleItems = document.getElementById('selectAllVisibleItems');
const API_UPDATE_STATUS_BATCH = './api/update_status_batch.php';
const selectedBatchItems = new Map();
```

Render one checkbox per row before the icon cell, using a stable JSON key based on full row identity:

```javascript
const identity = {
    docno: item.docno || '',
    cd_code: item.cd_code || '',
    unit: item.unit || '',
    price: item.UNITPRICE || ''
};
const selectionKey = JSON.stringify(identity);
row.dataset.selectionKey = selectionKey;
row.innerHTML = `
    <td class="px-3 py-4 text-center">
        <input type="checkbox" class="batch-select-checkbox" data-selection-key="${escapeHtml(selectionKey)}" aria-label="เลือกรายการ ${docNoHTML}">
    </td>
    ...
`;
```

Implement `clearBatchSelection()`, `updateBatchActionBar()`, and selection listeners so selected values come from the visible row datasets. `selectAllVisibleItems` must read only `.batch-select-checkbox` elements currently rendered in `tableBody`.

Use this state update behavior:

```javascript
const clearBatchSelection = () => {
    selectedBatchItems.clear();
    document.querySelectorAll('.batch-select-checkbox').forEach((checkbox) => {
        checkbox.checked = false;
    });
    selectAllVisibleItems.checked = false;
    selectAllVisibleItems.indeterminate = false;
    updateBatchActionBar();
};

const updateBatchActionBar = () => {
    const count = selectedBatchItems.size;
    batchSelectionSummary.textContent = `เลือกแล้ว ${count} รายการ`;
    batchActionBar.classList.toggle('hidden', count === 0);
    batchActionBar.classList.toggle('flex', count > 0);
    const visible = Array.from(document.querySelectorAll('.batch-select-checkbox'));
    const checkedCount = visible.filter((checkbox) => checkbox.checked).length;
    selectAllVisibleItems.checked = visible.length > 0 && checkedCount === visible.length;
    selectAllVisibleItems.indeterminate = checkedCount > 0 && checkedCount < visible.length;
};
```

- [ ] **Step 4: Clear selection when visible results change**

Call `clearBatchSelection()` at the entry points that alter visible rows:

```javascript
const fetchItems = async (...) => {
    clearBatchSelection();
    ...
};

const renderCurrentPage = (page = 1) => {
    clearBatchSelection();
    ...
};

const clearSelectedLocation = () => {
    clearBatchSelection();
    ...
};
```

Keep the clear operation idempotent so an automatic background reload safely resets visible selection.

- [ ] **Step 5: Prevent checkbox interaction from opening item details**

Before checking for `.item-row` in the delegated `tableBody` click handler, ignore checkbox interaction:

```javascript
if (e.target.closest('.batch-select-checkbox')) {
    return;
}
```

Use a `change` listener for `.batch-select-checkbox` to update the selection map and action bar.

### Task 3: Batch Dialog And Submission Flows

**Files:**
- Modify: `index.html`

- [ ] **Step 1: Establish failing action behavior**

After Task 2, select two visible rows and click each new batch action button.

Expected before this task: the button has no functional request flow and no confirmed batch update reaches the server.

- [ ] **Step 2: Extract reusable postpone reason prompt**

Move the existing reason-selection dialog from `handleUpdateStatus` into a reusable async function:

```javascript
const promptPostponeReason = async () => {
    const reason = await new Promise((resolve) => {
        Swal.fire({
            title: 'เลือกเหตุผลที่เลื่อน',
            html: `
                <div class="flex flex-wrap justify-center gap-3 mt-4">
                    <button id="reason1" class="bg-blue-500 text-white font-semibold py-2 px-4 rounded-lg">รอมารับ</button>
                    <button id="reason2" class="bg-orange-500 text-white font-semibold py-2 px-4 rounded-lg">ยังไม่มีสินค้า</button>
                    <button id="reason3" class="bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg">อื่นๆ</button>
                </div>`,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'ยกเลิก',
            didOpen: () => {
                document.getElementById('reason1').addEventListener('click', () => { Swal.close(); resolve('รอมารับ'); });
                document.getElementById('reason2').addEventListener('click', () => { Swal.close(); resolve('ยังไม่มีสินค้า'); });
                document.getElementById('reason3').addEventListener('click', () => { Swal.close(); resolve('อื่นๆ'); });
            }
        }).then((result) => {
            if (result.dismiss) resolve(null);
        });
    });
    if (reason !== 'อื่นๆ') return reason;
    const { value } = await Swal.fire({
        title: 'ระบุเหตุผลอื่นๆ',
        input: 'text',
        inputPlaceholder: 'กรุณาระบุเหตุผล...',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        inputValidator: (input) => input ? undefined : 'กรุณากรอกเหตุผล!'
    });
    return value || null;
};
```

Call this helper from the existing single-row `เลื่อน` path as well as the new batch path so both behaviors remain consistent.

- [ ] **Step 3: Implement batch submission helper**

Implement a helper that serializes the selected identity map and sends one request:

```javascript
const submitBatchStatus = async ({ status, remark = null, receivedByEmployee = null }) => {
    const formData = new FormData();
    formData.append('new_status', status);
    formData.append('items', JSON.stringify(Array.from(selectedBatchItems.values())));
    if (remark) formData.append('remark', remark);
    if (receivedByEmployee) formData.append('received_by_employee', receivedByEmployee);

    const response = await fetch(API_UPDATE_STATUS_BATCH, { method: 'POST', body: formData });
    const result = await response.json();
    if (!response.ok || !result.success) {
        throw new Error(result.message || 'ไม่สามารถอัปเดตรายการที่เลือกได้');
    }
};
```

On successful submission, show success feedback and call `fetchItems(currentPage)`; on error show the existing SweetAlert error pattern and do not claim records changed.

- [ ] **Step 4: Implement receive-all flow without quantity entry**

On `batchReceiveBtn` click:

```javascript
const employee = await promptReceivingEmployee();
if (!employee) return;
const confirmed = await Swal.fire({
    title: 'ยืนยันรับทั้งหมด?',
    text: `รับจำนวนคงเหลือทั้งหมด ${selectedBatchItems.size} รายการ ใช่หรือไม่?`,
    icon: 'question',
    showCancelButton: true
});
if (!confirmed.isConfirmed) return;
await submitBatchStatus({ status: 'รับแล้ว', receivedByEmployee: employee.employee_name });
```

Do not call `promptReceiveQuantity()` in this path.

- [ ] **Step 5: Implement postpone and cancel batch flows**

On postpone, call `promptPostponeReason()` exactly once, confirm the selected count, then submit `{ status: 'เลื่อน', remark }`. On cancel, confirm the selected count once and submit `{ status: 'ยกเลิก' }`.

- [ ] **Step 6: Run browser verification**

Use the in-app browser against `http://localhost/queue-order/index.html` and verify:

```text
- Selecting individual visible rows updates the selected count.
- Header checkbox selects only the current page; navigating pages clears it.
- Search/filter/background-or-action refresh clears selection.
- Clicking a checkbox does not open details.
- Batch receive asks for employee and confirmation but never opens quantity input.
- Single-row receive still opens its quantity input.
- Batch postpone uses one shared reason prompt.
- Batch cancel shows one confirmation.
```

Expected: all controls render and behave without browser console errors.

### Task 4: Integration And Regression Verification

**Files:**
- Verify: `api/update_status_batch.php`
- Verify: `index.html`

- [ ] **Step 1: Run static validation**

Run:

```powershell
php -l api\update_status_batch.php
php -l api\update_status.php
```

Expected: both files report no syntax errors.

- [ ] **Step 2: Verify atomic batch receive**

Choose two disposable pending rows, including a partially received row when available. Batch receive them with one employee.

Expected:

```text
- Every selected row is removed from pending and appears as รับแล้ว.
- Each row's received_qty_total equals qty.
- Receive history contains one new entry per selected row with its remaining quantity and chosen employee.
```

Then submit a receive batch where one row is already complete or has invalid identity.

Expected: API reports failure and none of that request's valid rows change.

- [ ] **Step 3: Verify postpone and cancel**

Select two disposable pending rows for each action.

Expected:

```text
- เลื่อน sets the shared remark on all selected rows.
- ยกเลิก sets the status on all selected rows and clears obsolete receive summary fields as current single-row behavior does.
```

- [ ] **Step 4: Verify single-row regressions**

Run existing flows on separate disposable rows:

```text
- Receive part of one row using the quantity calculator; status becomes รับบางส่วน.
- Complete that row using the existing single-row receive button.
- Postpone one row with both a predefined and custom reason.
- Cancel one row.
```

Expected: existing behavior is unchanged.

- [ ] **Step 5: Review diff and commit implementation**

Run:

```powershell
git diff -- api\update_status_batch.php index.html
git status --short
```

Ensure only intended implementation files and the plan document are included, then commit:

```powershell
git add api\update_status_batch.php index.html docs\superpowers\plans\2026-05-25-batch-status-actions.md
git commit -m "Add batch status actions"
```
