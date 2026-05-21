# Partial Receive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add partial item receiving with calculator quantity entry, cumulative received totals, and per-receive history across all configured database profiles.

**Architecture:** Keep the existing `update_status.php` endpoint backward-compatible while adding transactional partial receive behavior when `received_qty` is sent. Store fast display totals on `transfer_data_from_mssql` and immutable receive events in `item_receive_history`. Update list/detail APIs to expose receive progress and history, then update `index.html` and `history.html` to show and continue partial receives.

**Tech Stack:** PHP 8 style mysqli APIs, MySQL/MariaDB, vanilla JavaScript, SweetAlert2, Tailwind utility classes, existing session-based database profile selection.

---

## File Structure

- Create `setup_partial_receive.php`: Browser-runnable setup script that iterates every profile from `db_profiles.php`, creates `item_receive_history`, adds cumulative columns, and backfills old `รับแล้ว` rows.
- Modify `api/update_status.php`: Add transaction-based partial receive logic while preserving existing status updates.
- Modify `api/fetch_items.php`: Include cumulative receive fields and include `รับบางส่วน` in the main pending list.
- Modify `api/get_item_details.php`: Return receive history with the item detail response.
- Modify `index.html`: Add receive progress display and calculator quantity popup for receiving partial quantities.
- Modify `history.html`: Add support/display for `รับบางส่วน` and receive history in details.
- Optionally modify `api/fetch_single_item.php`: Include cumulative receive fields if this endpoint is still used by notification/detail refresh flows.

## Task 1: Database Setup Script

**Files:**
- Create: `setup_partial_receive.php`

- [ ] **Step 1: Create the setup script shell**

Create `setup_partial_receive.php` with profile iteration and safe HTML output:

```php
<?php

require_once __DIR__ . '/api/_bootstrap.php';

$profiles = app_db_profiles();
$results = [];

foreach ($profiles as $key => $profile) {
    $mysqli = new mysqli($profile['host'], $profile['user'], $profile['pass'], $profile['name']);
    if ($mysqli->connect_error) {
        $results[] = ['profile' => $key, 'success' => false, 'message' => $mysqli->connect_error];
        continue;
    }

    $mysqli->set_charset('utf8mb4');

    try {
        setup_partial_receive_schema($mysqli);
        $results[] = ['profile' => $key, 'success' => true, 'message' => 'Schema ready'];
    } catch (Throwable $error) {
        $results[] = ['profile' => $key, 'success' => false, 'message' => $error->getMessage()];
    } finally {
        $mysqli->close();
    }
}

function setup_partial_receive_schema(mysqli $conn): void
{
    // Implementation added in the next step.
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Partial Receive Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 p-8">
    <main class="mx-auto max-w-3xl rounded-lg bg-white p-6 shadow">
        <h1 class="mb-4 text-2xl font-bold">Partial Receive Setup</h1>
        <div class="space-y-3">
            <?php foreach ($results as $result): ?>
                <div class="rounded border p-3 <?= $result['success'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' ?>">
                    <div class="font-semibold"><?= htmlspecialchars($result['profile']) ?></div>
                    <div><?= htmlspecialchars($result['message']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
```

- [ ] **Step 2: Add idempotent schema creation**

Replace the empty `setup_partial_receive_schema` function body with:

```php
function setup_partial_receive_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS item_receive_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            docno VARCHAR(100) NOT NULL,
            cd_code VARCHAR(100) NOT NULL,
            received_qty DECIMAL(15,3) NOT NULL,
            received_by_employee VARCHAR(255) NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            note VARCHAR(500) NULL,
            INDEX idx_receive_history_item (docno, cd_code),
            INDEX idx_receive_history_received_at (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    add_column_if_missing($conn, 'transfer_data_from_mssql', 'received_qty_total', "ALTER TABLE transfer_data_from_mssql ADD COLUMN received_qty_total DECIMAL(15,3) NOT NULL DEFAULT 0 AFTER received_by_employee");
    add_column_if_missing($conn, 'transfer_data_from_mssql', 'received_count', "ALTER TABLE transfer_data_from_mssql ADD COLUMN received_count INT NOT NULL DEFAULT 0 AFTER received_qty_total");

    $receivedStatus = 'รับแล้ว';
    $stmt = $conn->prepare("
        UPDATE transfer_data_from_mssql
        SET received_qty_total = COALESCE(qty, 0), received_count = 1
        WHERE delivery_status = ?
          AND COALESCE(received_qty_total, 0) = 0
    ");
    $stmt->bind_param('s', $receivedStatus);
    $stmt->execute();
    $stmt->close();
}

function add_column_if_missing(mysqli $conn, string $table, string $column, string $alterSql): void
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (int) $result->fetch_assoc()['total'] > 0;
    $stmt->close();

    if (!$exists && !$conn->query($alterSql)) {
        throw new RuntimeException($conn->error);
    }
}
```

- [ ] **Step 3: Run syntax check**

Run: `php -l setup_partial_receive.php`

Expected: `No syntax errors detected in setup_partial_receive.php`

- [ ] **Step 4: Run setup in browser**

Open `http://localhost/queue-order/setup_partial_receive.php`.

Expected: all profiles from `db_profiles.php` show `Schema ready`, including `queue`, `queue_hq`, and `queue_nrg`.

- [ ] **Step 5: Commit**

```bash
git add setup_partial_receive.php
git commit -m "Add partial receive setup script"
```

## Task 2: Update Status API

**Files:**
- Modify: `api/update_status.php`

- [ ] **Step 1: Add helper functions near the top after `$conn = app_db();`**

```php
function app_status_received(): string
{
    return 'รับแล้ว';
}

function app_status_partial_received(): string
{
    return 'รับบางส่วน';
}

function app_status_postponed(): string
{
    return 'เลื่อน';
}

function parse_receive_quantity(?string $value): ?float
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $normalized = str_replace(',', '', trim($value));
    if (!is_numeric($normalized)) {
        throw new Exception('Invalid received quantity.');
    }

    return (float) $normalized;
}
```

- [ ] **Step 2: Read `received_qty` from POST**

Inside the existing `try` block after `$receivedByEmployee`, add:

```php
$receivedQty = parse_receive_quantity($_POST['received_qty'] ?? null);
```

- [ ] **Step 3: Branch receive requests to partial receive logic**

Before building `$setClauses`, add:

```php
if ($newStatus === app_status_received()) {
    if ($receivedByEmployee === null || $receivedByEmployee === '') {
        throw new Exception('Missing receiving employee.');
    }

    handle_receive_status_update($conn, $docno, $cdCode, $receivedByEmployee, $receivedQty);
    $conn->close();
    app_json_response(['success' => true, 'message' => 'Receive status updated successfully.']);
}
```

Remove the old duplicated `if ($newStatus === 'รับแล้ว'...)` guard and the old `received_by_employee` branch for `รับแล้ว`, because receive status is now handled before the generic status update.

- [ ] **Step 4: Add transactional receive function before the catch block**

```php
function handle_receive_status_update(mysqli $conn, string $docno, string $cdCode, string $receivedByEmployee, ?float $receivedQty): void
{
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT qty, COALESCE(received_qty_total, 0) AS received_qty_total
            FROM transfer_data_from_mssql
            WHERE docno = ? AND cd_code = ?
            FOR UPDATE
        ");
        if ($stmt === false) {
            throw new Exception('Failed to prepare item lookup: ' . $conn->error);
        }

        $stmt->bind_param('ss', $docno, $cdCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        $stmt->close();

        if (!$item) {
            throw new Exception('Item not found.');
        }

        $totalQty = (float) $item['qty'];
        $currentReceivedQty = (float) $item['received_qty_total'];
        $remainingQty = max(0, $totalQty - $currentReceivedQty);
        $qtyToReceive = $receivedQty ?? $remainingQty;

        if ($qtyToReceive <= 0) {
            throw new Exception('Received quantity must be greater than zero.');
        }

        if ($qtyToReceive - $remainingQty > 0.0001) {
            throw new Exception('Received quantity cannot exceed remaining quantity.');
        }

        $newReceivedQty = $currentReceivedQty + $qtyToReceive;
        $newStatus = ($newReceivedQty + 0.0001 >= $totalQty) ? app_status_received() : app_status_partial_received();

        $historyStmt = $conn->prepare("
            INSERT INTO item_receive_history (docno, cd_code, received_qty, received_by_employee)
            VALUES (?, ?, ?, ?)
        ");
        if ($historyStmt === false) {
            throw new Exception('Failed to prepare receive history insert: ' . $conn->error);
        }

        $historyStmt->bind_param('ssds', $docno, $cdCode, $qtyToReceive, $receivedByEmployee);
        $historyStmt->execute();
        $historyStmt->close();

        $updateStmt = $conn->prepare("
            UPDATE transfer_data_from_mssql
            SET delivery_status = ?,
                delivery_remark = NULL,
                received_by_employee = ?,
                received_qty_total = ?,
                received_count = received_count + 1
            WHERE docno = ? AND cd_code = ?
        ");
        if ($updateStmt === false) {
            throw new Exception('Failed to prepare item receive update: ' . $conn->error);
        }

        $updateStmt->bind_param('ssdss', $newStatus, $receivedByEmployee, $newReceivedQty, $docno, $cdCode);
        $updateStmt->execute();
        $updateStmt->close();

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
```

- [ ] **Step 5: Keep generic status reset behavior clean**

In the generic non-receive update path, when status is not `เลื่อน`, keep `delivery_remark = NULL`; when status is not receive, keep `received_by_employee = NULL`. Do not reset `received_qty_total` or history for postpone/cancel in this task.

- [ ] **Step 6: Run syntax check**

Run: `php -l api/update_status.php`

Expected: `No syntax errors detected in api/update_status.php`

- [ ] **Step 7: Commit**

```bash
git add api/update_status.php
git commit -m "Add partial receive status updates"
```

## Task 3: List And Detail APIs

**Files:**
- Modify: `api/fetch_items.php`
- Modify: `api/get_item_details.php`
- Modify: `api/fetch_single_item.php`

- [ ] **Step 1: Include partial status in main list filter**

In `api/fetch_items.php`, replace the default pending filter:

```php
$whereClauses[] = "(delivery_status IS NULL OR delivery_status = '')";
```

with:

```php
$whereClauses[] = "(delivery_status IS NULL OR delivery_status = '' OR delivery_status = 'รับบางส่วน')";
```

- [ ] **Step 2: Include receive totals in list query**

In the SELECT list, replace:

```php
delivery_status, delivery_remark, received_by_employee, last_update
```

with:

```php
delivery_status, delivery_remark, received_by_employee,
COALESCE(received_qty_total, 0) AS received_qty_total,
COALESCE(received_count, 0) AS received_count,
last_update
```

- [ ] **Step 3: Add receive history to details API**

In `api/get_item_details.php`, after fetching `$item` and before closing the connection, add:

```php
$history = [];
$historyStmt = $conn->prepare("
    SELECT id, received_qty, received_by_employee, received_at, note
    FROM item_receive_history
    WHERE docno = ? AND cd_code = ?
    ORDER BY received_at ASC, id ASC
");
if ($historyStmt === false) {
    throw new Exception('Prepare receive history failed: ' . $conn->error);
}

$historyStmt->bind_param('ss', $_GET['docno'], $_GET['cd_code']);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();
while ($row = $historyResult->fetch_assoc()) {
    $history[] = $row;
}
$historyStmt->close();
$item['receive_history'] = $history;
```

Keep the existing response shape as `data: $item`.

- [ ] **Step 4: Include receive totals in single item API**

In `api/fetch_single_item.php`, add these fields to the SELECT:

```sql
COALESCE(received_qty_total, 0) AS received_qty_total,
COALESCE(received_count, 0) AS received_count
```

- [ ] **Step 5: Run syntax checks**

Run:

```bash
php -l api/fetch_items.php
php -l api/get_item_details.php
php -l api/fetch_single_item.php
```

Expected: no syntax errors for all three files.

- [ ] **Step 6: Commit**

```bash
git add api/fetch_items.php api/get_item_details.php api/fetch_single_item.php
git commit -m "Expose partial receive data in APIs"
```

## Task 4: Index Page Receive UI

**Files:**
- Modify: `index.html`

- [ ] **Step 1: Add row dataset fields**

In `renderTable`, after existing `row.dataset.shipflag`, add:

```javascript
row.dataset.qty = item.qty || '0';
row.dataset.receivedQty = item.received_qty_total || '0';
```

- [ ] **Step 2: Add receive progress display helper**

Near other helper functions, add:

```javascript
const formatQty = (value) => {
    const number = parseFloat(value || 0);
    return Number.isInteger(number) ? number.toLocaleString() : number.toLocaleString(undefined, { maximumFractionDigits: 3 });
};

const buildReceiveProgressHTML = (item) => {
    const totalQty = parseFloat(item.qty || 0);
    const receivedQty = parseFloat(item.received_qty_total || 0);
    if (receivedQty <= 0) return '';

    const percent = totalQty > 0 ? Math.min(100, (receivedQty / totalQty) * 100) : 0;
    return `
        <div class="mt-1 min-w-[9rem]">
            <div class="mb-1 text-xs font-semibold text-green-700">รับแล้ว ${formatQty(receivedQty)} / ${formatQty(totalQty)} ${item.unit || ''}</div>
            <div class="h-2 rounded-full bg-slate-200">
                <div class="h-2 rounded-full bg-green-500" style="width: ${percent}%"></div>
            </div>
        </div>
    `;
};
```

- [ ] **Step 3: Show progress in the status/info column**

After `deliveryInfo` is calculated for each row, add:

```javascript
deliveryInfo += buildReceiveProgressHTML(item);
```

- [ ] **Step 4: Add calculator popup function**

Add this function before `handleUpdateStatus`:

```javascript
const promptReceiveQuantity = async ({ totalQty, receivedQty, unit }) => {
    const remainingQty = Math.max(0, totalQty - receivedQty);
    const remainingText = `${formatQty(remainingQty)} ${unit || ''}`;

    const result = await Swal.fire({
        title: 'จำนวนที่รับครั้งนี้',
        html: `
            <div class="space-y-4 text-left">
                <div class="grid grid-cols-3 gap-2 text-center text-sm">
                    <div class="rounded-lg bg-slate-100 p-2"><div class="text-slate-500">ทั้งหมด</div><div class="font-bold">${formatQty(totalQty)}</div></div>
                    <div class="rounded-lg bg-green-50 p-2"><div class="text-slate-500">รับแล้ว</div><div class="font-bold text-green-700">${formatQty(receivedQty)}</div></div>
                    <div class="rounded-lg bg-orange-50 p-2"><div class="text-slate-500">คงเหลือ</div><div class="font-bold text-orange-700">${remainingText}</div></div>
                </div>
                <input id="receiveQtyInput" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-right text-2xl font-bold" inputmode="decimal" value="${formatQty(remainingQty).replace(/,/g, '')}">
                <div class="grid grid-cols-3 gap-2">
                    ${['7','8','9','4','5','6','1','2','3','0','.','⌫'].map(key => `<button type="button" class="calc-key rounded-lg bg-slate-100 py-3 font-bold hover:bg-slate-200" data-key="${key}">${key}</button>`).join('')}
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="clearReceiveQty" class="rounded-lg bg-red-100 py-3 font-semibold text-red-700 hover:bg-red-200">ล้าง</button>
                    <button type="button" id="fillRemainingQty" class="rounded-lg bg-green-100 py-3 font-semibold text-green-700 hover:bg-green-200">รับทั้งหมดที่เหลือ</button>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'ยืนยันจำนวนรับ',
        cancelButtonText: 'ยกเลิก',
        didOpen: () => {
            const input = document.getElementById('receiveQtyInput');
            document.querySelectorAll('.calc-key').forEach((button) => {
                button.addEventListener('click', () => {
                    const key = button.dataset.key;
                    if (key === '⌫') {
                        input.value = input.value.slice(0, -1);
                    } else if (key === '.' && input.value.includes('.')) {
                        return;
                    } else {
                        input.value += key;
                    }
                    input.dispatchEvent(new Event('input'));
                });
            });
            document.getElementById('clearReceiveQty').addEventListener('click', () => { input.value = ''; });
            document.getElementById('fillRemainingQty').addEventListener('click', () => { input.value = String(remainingQty); });
        },
        preConfirm: () => {
            const value = parseFloat((document.getElementById('receiveQtyInput').value || '').replace(/,/g, ''));
            if (!Number.isFinite(value) || value <= 0) {
                Swal.showValidationMessage('กรุณากรอกจำนวนที่มากกว่า 0');
                return false;
            }
            if (value - remainingQty > 0.0001) {
                Swal.showValidationMessage('จำนวนรับต้องไม่เกินจำนวนคงเหลือ');
                return false;
            }
            return value;
        }
    });

    return result.isConfirmed ? result.value : null;
};
```

- [ ] **Step 5: Send received quantity in `handleUpdateStatus`**

In `handleUpdateStatus`, define:

```javascript
let receivedQty = null;
```

After employee selection for `รับแล้ว`, read row data and call popup:

```javascript
const row = button.closest('tr');
const totalQty = parseFloat(row?.dataset.qty || '0');
const currentReceivedQty = parseFloat(row?.dataset.receivedQty || '0');
receivedQty = await promptReceiveQuantity({
    totalQty,
    receivedQty: currentReceivedQty,
    unit: row?.dataset.unit || ''
});
if (receivedQty === null) {
    return;
}
```

When building `formData`, add:

```javascript
if (receivedQty !== null) {
    formData.append('received_qty', receivedQty);
}
```

- [ ] **Step 6: Run browser smoke test**

Open `http://localhost/queue-order/index.html`, choose a location, click a receive button.

Expected: employee popup appears, then quantity calculator appears, and invalid quantities are blocked before submit.

- [ ] **Step 7: Commit**

```bash
git add index.html
git commit -m "Add partial receive calculator UI"
```

## Task 5: History Page Support

**Files:**
- Modify: `history.html`
- Modify: `index.html`

- [ ] **Step 1: Add partial history link on index page**

Near the existing received/postponed/cancelled links, add a link with id `partialReceivedLink` labeled `ดูรายการรับบางส่วน`.

In `updateHeader`, set:

```javascript
document.getElementById('partialReceivedLink').href = `history.html?location=${encodeURIComponent(selectedLocationInfo.location_code)}&status=${encodeURIComponent('รับบางส่วน')}&location_name=${encodeURIComponent(selectedLocationInfo.location)}`;
```

- [ ] **Step 2: Add status color for partial receive**

In `history.html`, update `statusColors`:

```javascript
const statusColors = { 'รับแล้ว': 'bg-green-100 text-green-800', 'รับบางส่วน': 'bg-orange-100 text-orange-800', 'เลื่อน': 'bg-yellow-100 text-yellow-800', 'ยกเลิก': 'bg-red-100 text-red-800' };
```

Allow the page title/header logic to accept `รับบางส่วน`.

- [ ] **Step 3: Show receive progress in history table**

Reuse the same `formatQty` and `buildReceiveProgressHTML` helpers from `index.html`, adapted to `history.html`, and append progress to `deliveryInfo`.

- [ ] **Step 4: Show receive history in details modal**

In both `index.html` and `history.html` detail modal rendering, add a section after delivery info:

```javascript
const receiveHistoryHTML = Array.isArray(item.receive_history) && item.receive_history.length > 0
    ? item.receive_history.map((entry, index) => `
        <div class="flex items-center justify-between border-b py-2 text-sm">
            <div>
                <div class="font-semibold">ครั้งที่ ${index + 1}: ${formatQty(entry.received_qty)} ${item.Lname_unit || ''}</div>
                <div class="text-slate-500">${entry.received_by_employee || '-'}</div>
            </div>
            <div class="text-right text-slate-500">${entry.received_at ? new Date(entry.received_at).toLocaleString('th-TH', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' น.' : '-'}</div>
        </div>
    `).join('')
    : '<p class="text-sm text-slate-500">ยังไม่มีประวัติการรับแบบแยกครั้ง</p>';
```

Then add:

```html
<section>
    <h3 class="mt-4 mb-3 border-b pb-2 text-lg font-semibold text-gray-700">ประวัติการรับสินค้า</h3>
    <div class="rounded-lg border border-slate-200 px-3">${receiveHistoryHTML}</div>
</section>
```

- [ ] **Step 5: Run browser smoke tests**

Open:

```text
http://localhost/queue-order/history.html?status=รับบางส่วน
http://localhost/queue-order/history.html?status=รับแล้ว
```

Expected: both pages render without JavaScript console errors. Detail modal shows receive history section.

- [ ] **Step 6: Commit**

```bash
git add index.html history.html
git commit -m "Show partial receive history"
```

## Task 6: End-To-End Verification

**Files:**
- No new code files unless fixing defects found during verification.

- [ ] **Step 1: Run PHP syntax checks**

Run:

```bash
php -l setup_partial_receive.php
php -l api/update_status.php
php -l api/fetch_items.php
php -l api/get_item_details.php
php -l api/fetch_single_item.php
```

Expected: all report no syntax errors.

- [ ] **Step 2: Verify profile setup**

Open `http://localhost/queue-order/setup_partial_receive.php`.

Expected: `queue`, `queue_hq`, and `queue_nrg` all show success.

- [ ] **Step 3: Verify full receive**

On a pending test row with quantity 10, receive 10.

Expected:

- UI shows success.
- Row moves out of main pending list.
- History `รับแล้ว` contains the row.
- Detail modal shows one receive history row for 10.

- [ ] **Step 4: Verify partial then complete receive**

On a pending test row with quantity 10, receive 4.

Expected:

- Row status becomes `รับบางส่วน`.
- Row remains on `index.html`.
- Progress shows `รับแล้ว 4 / 10`.
- History `รับบางส่วน` contains the row.

Receive remaining 6.

Expected:

- Row status becomes `รับแล้ว`.
- Row moves to received history.
- Detail modal shows two receive history rows: 4 and 6.

- [ ] **Step 5: Verify validation**

Try receiving 0 and more than remaining.

Expected: UI validation blocks submit. API validation also rejects direct invalid requests.

- [ ] **Step 6: Verify old actions**

Test `เลื่อน`, `ยกเลิก`, delete from history, and status reset.

Expected: existing behaviors still work and do not delete receive history.

- [ ] **Step 7: Final commit**

If verification fixes were needed:

```bash
git add <changed-files>
git commit -m "Fix partial receive verification issues"
```

If no fixes were needed, no commit is required.
