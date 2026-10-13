<?php

require_once __DIR__ . '/_bootstrap.php';

function app_ensure_deleted_items_table(mysqli $conn): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/setup_deleted_items.sql');
    if ($sql === false || !$conn->query($sql)) {
        throw new RuntimeException('Cannot create deleted items archive');
    }
    $hasReason = (int) app_execute($conn, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deleted_transfer_items' AND COLUMN_NAME = 'deletion_reason'")->fetch_row()[0] > 0;
    if (!$hasReason) {
        // MariaDB's IF NOT EXISTS also handles concurrent first requests safely.
        $conn->query('ALTER TABLE deleted_transfer_items ADD COLUMN IF NOT EXISTS deletion_reason TEXT NULL AFTER snapshot_json');
    }
}

function app_require_archive_transactions(mysqli $conn): void
{
    $result = app_execute($conn, "SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('transfer_data_from_mssql', 'deleted_transfer_items')");
    $engines = [];
    while ($row = $result->fetch_assoc()) {
        $engines[$row['TABLE_NAME']] = $row['ENGINE'];
    }
    foreach (['transfer_data_from_mssql', 'deleted_transfer_items'] as $table) {
        if (strcasecmp($engines[$table] ?? '', 'InnoDB') !== 0) {
            throw new RuntimeException('Archive requires InnoDB: ' . $table);
        }
    }
}

function app_deleted_identity_value(array $item, string $key): string
{
    $value = $item[$key] ?? '';
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('ข้อมูลรายการไม่ถูกต้อง');
    }
    return trim((string) $value);
}

// DDL is completed before starting the transaction that stores and removes the rows.
function app_archive_items(mysqli $conn, array $items, ?array $user, $reason, bool $salesScope = false): int
{
    if (!is_string($reason) || !preg_match('//u', $reason)) {
        throw new InvalidArgumentException('กรุณากรอกเหตุผลการลบ');
    }
    $reason = preg_replace('/^\s+|\s+$/u', '', $reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
        throw new InvalidArgumentException('กรุณากรอกเหตุผลการลบ 1–1000 ตัวอักษร');
    }
    if (count($items) === 0 || count($items) > 100 || array_keys($items) !== range(0, count($items) - 1)) {
        throw new InvalidArgumentException('กรุณาเลือก 1–100 รายการที่ต้องการลบ');
    }
    $targets = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('ข้อมูลรายการไม่ถูกต้อง');
        }
        $docno = app_deleted_identity_value($item, 'docno');
        $cdCode = app_deleted_identity_value($item, 'cd_code');
        $locationCode = app_deleted_identity_value($item, 'location_code');
        $subId = app_deleted_identity_value($item, 'sub_id');
        $unit = app_deleted_identity_value($item, 'unit');
        $price = app_deleted_identity_value($item, 'price');
        if ($docno === '' || $cdCode === '' || (!$salesScope && $locationCode === '')
            || ($price !== '' && !is_numeric($price)) || (($unit === '') !== ($price === ''))
            || ($salesScope && ($unit === '' || $price === ''))) {
            throw new InvalidArgumentException('ข้อมูลรายการไม่ครบถ้วน');
        }
        $where = 'docno = ? AND cd_code = ?';
        $params = [$docno, $cdCode];
        if ($locationCode !== '') {
            $where .= ' AND location_code = ?';
            $params[] = $locationCode;
        }
        if ($subId !== '') {
            $where .= ' AND sub_id = ?';
            $params[] = $subId;
        }
        if ($unit !== '') {
            // Bind decimals as strings to preserve their database precision.
            $where .= ' AND Lname_unit = ? AND UNITPRICE = ?';
            $params[] = $unit;
            $params[] = $price;
        }
        if ($salesScope) {
            if (!$user) {
                throw new DomainException('กรุณาเข้าสู่ระบบ');
            }
            $owner = app_can_view_all_bills($user)
                ? app_deleted_identity_value($item, 'user_lname')
                : trim((string) ($user['sales_lname'] ?? ''));
            if ($owner === '') {
                throw new InvalidArgumentException('ข้อมูลพนักงานขายไม่ครบถ้วน');
            }
            $where .= ' AND user_lname = ?';
            $params[] = $owner;
        } elseif (!empty($user['default_location_code'])) {
            $where .= " AND FIND_IN_SET(?, REPLACE(location_code, ' ', '')) > 0";
            $params[] = $user['default_location_code'];
        }
        $targets[] = [$where, $params];
    }

    app_ensure_deleted_items_table($conn);
    app_require_archive_transactions($conn);
    $hasHistory = (int) app_execute($conn, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_receive_history'")->fetch_row()[0] > 0;
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Cannot start archive transaction');
    }
    try {
        foreach ($targets as [$where, $params]) {
            $types = str_repeat('s', count($params));
            $result = app_execute($conn, 'SELECT * FROM transfer_data_from_mssql WHERE ' . $where . ' LIMIT 2 FOR UPDATE', $types, $params);
            if ($result->num_rows !== 1) {
                throw new DomainException('ไม่พบรายการที่ลบได้ หรือข้อมูลระบุรายการไม่ชัดเจน');
            }
            $row = $result->fetch_assoc();
            $fields = array_change_key_case($row, CASE_LOWER);
            $history = [];
            if ($hasHistory) {
                $historyResult = app_execute($conn, 'SELECT * FROM item_receive_history
                    WHERE docno = ? AND cd_code = ? AND lname_unit <=> ? AND unitprice <=> ?
                    ORDER BY received_at, id', 'ssss', [$fields['docno'], $fields['cd_code'], $fields['lname_unit'], $fields['unitprice']]);
                $history = $historyResult->fetch_all(MYSQLI_ASSOC);
                if (!$history) {
                    $history = app_execute($conn, 'SELECT * FROM item_receive_history
                        WHERE docno = ? AND cd_code = ? AND lname_unit IS NULL AND unitprice IS NULL
                        ORDER BY received_at, id', 'ss', [$row['docno'], $row['cd_code']])->fetch_all(MYSQLI_ASSOC);
                }
            }
            $snapshot = json_encode(['item' => $row, 'receive_history' => $history], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $deletedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
            app_execute($conn, 'INSERT INTO deleted_transfer_items
                (docno, sub_id, cd_code, customer_name, cd_name, location_code, location, user_lname,
                 snapshot_json, deletion_reason, deleted_by_user_id, deleted_by_username, deleted_by_name, deleted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'ssssssssssisss', [
                $fields['docno'], $fields['sub_id'] ?? null, $fields['cd_code'], $fields['custname'] ?? null,
                $fields['cd_name'] ?? null, $fields['location_code'] ?? null, $fields['location'] ?? null,
                $fields['user_lname'] ?? null, $snapshot, $reason, $user['id'] ?? null, $user['username'] ?? null,
                $user['full_name'] ?? $user['username'] ?? null, $deletedAt,
            ]);
            $delete = $conn->prepare('DELETE FROM transfer_data_from_mssql WHERE ' . $where);
            if (!$delete) {
                throw new RuntimeException('Cannot prepare source removal');
            }
            try {
                $delete->bind_param($types, ...$params);
                if (!$delete->execute()) {
                    throw new RuntimeException('Cannot remove archived source');
                }
                $affected = $delete->affected_rows;
            } finally {
                $delete->close();
            }
            if ($affected !== 1) {
                throw new RuntimeException('Source changed during archive');
            }
        }
        if (!$conn->commit()) {
            throw new RuntimeException('Cannot commit archive transaction');
        }
        return count($targets);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
