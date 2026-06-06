<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function app_batch_status_received(): string
{
    return 'รับแล้ว';
}

function app_batch_status_postponed(): string
{
    return 'เลื่อน';
}

function app_batch_status_cancelled(): string
{
    return 'ยกเลิก';
}

function parse_batch_optional_price($value): ?float
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new Exception('Invalid price.');
    }

    $normalized = str_replace(',', '', trim((string)$value));
    if ($normalized === '' || !is_numeric($normalized)) {
        throw new Exception('Invalid price.');
    }

    return (float)$normalized;
}

function parse_batch_items(string $itemsJson): array
{
    $items = json_decode($itemsJson, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($items) || count($items) === 0) {
        throw new Exception('Items must be a non-empty JSON array.');
    }

    if (array_keys($items) !== range(0, count($items) - 1)) {
        throw new Exception('Items must be a non-empty JSON array.');
    }

    $normalizedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new Exception('Invalid item identity.');
        }

        $docno = isset($item['docno']) && is_scalar($item['docno'])
            ? trim((string)$item['docno'])
            : '';
        $cdCode = isset($item['cd_code']) && is_scalar($item['cd_code'])
            ? trim((string)$item['cd_code'])
            : '';
        $subId = isset($item['sub_id']) && is_scalar($item['sub_id'])
            ? trim((string)$item['sub_id'])
            : '';
        $locationCode = isset($item['location_code']) && is_scalar($item['location_code'])
            ? trim((string)$item['location_code'])
            : '';
        if ($docno === '' || $cdCode === '' || $locationCode === '') {
            throw new Exception('Invalid item identity.');
        }

        if (isset($item['unit']) && !is_scalar($item['unit'])) {
            throw new Exception('Invalid item unit.');
        }

        $unit = isset($item['unit']) ? trim((string)$item['unit']) : '';
        $unitPrice = parse_batch_optional_price($item['price'] ?? null);
        if (($unit !== '') !== ($unitPrice !== null)) {
            throw new Exception('Invalid item identity.');
        }

        $normalizedItems[] = [
            'docno' => $docno,
            'sub_id' => $subId,
            'cd_code' => $cdCode,
            'location_code' => $locationCode,
            'unit' => $unit,
            'price' => $unitPrice,
        ];
    }

    return $normalizedItems;
}

function execute_batch_received(
    mysqli $conn,
    array $item,
    string $receivedByEmployee
): void {
    $docno = $item['docno'];
    $subId = $item['sub_id'];
    $cdCode = $item['cd_code'];
    $locationCode = $item['location_code'];
    $unit = $item['unit'];
    $unitPrice = $item['price'];
    $hasFullIdentity = $unit !== '' && $unitPrice !== null;

    $selectSql = 'SELECT qty, COALESCE(received_qty_total, 0) AS received_qty_total
        FROM transfer_data_from_mssql
        WHERE docno = ? AND cd_code = ? AND location_code = ?';
    if ($subId !== '') {
        $selectSql .= ' AND sub_id = ?';
    }
    if ($hasFullIdentity) {
        $selectSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
    }
    $selectSql .= ' FOR UPDATE';

    $stmt = $conn->prepare($selectSql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    if ($subId !== '' && $hasFullIdentity) {
        $stmt->bind_param('sssssd', $docno, $cdCode, $locationCode, $subId, $unit, $unitPrice);
    } elseif ($subId !== '') {
        $stmt->bind_param('ssss', $docno, $cdCode, $locationCode, $subId);
    } elseif ($hasFullIdentity) {
        $stmt->bind_param('ssssd', $docno, $cdCode, $locationCode, $unit, $unitPrice);
    } else {
        $stmt->bind_param('sss', $docno, $cdCode, $locationCode);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Item not found.');
    }

    $totalQty = (float)$row['qty'];
    $currentQty = (float)$row['received_qty_total'];
    $remainingQty = $totalQty - $currentQty;
    if ($remainingQty <= 0) {
        throw new Exception('Item has no remaining quantity.');
    }

    if ($hasFullIdentity) {
        $stmt = $conn->prepare(
            'INSERT INTO item_receive_history (docno, cd_code, lname_unit, unitprice, received_qty, received_by_employee)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO item_receive_history (docno, cd_code, received_qty, received_by_employee)
            VALUES (?, ?, ?, ?)'
        );
    }
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    if ($hasFullIdentity) {
        $stmt->bind_param('sssdds', $docno, $cdCode, $unit, $unitPrice, $remainingQty, $receivedByEmployee);
    } else {
        $stmt->bind_param('ssds', $docno, $cdCode, $remainingQty, $receivedByEmployee);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }
    $stmt->close();

    $newStatus = app_batch_status_received();
    $updateSql = 'UPDATE transfer_data_from_mssql
        SET delivery_status = ?,
            delivery_remark = NULL,
            received_by_employee = ?,
            received_qty_total = qty,
            received_count = received_count + 1
        WHERE docno = ? AND cd_code = ? AND location_code = ?';
    if ($subId !== '') {
        $updateSql .= ' AND sub_id = ?';
    }
    if ($hasFullIdentity) {
        $updateSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
    }

    $stmt = $conn->prepare($updateSql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    if ($subId !== '' && $hasFullIdentity) {
        $stmt->bind_param('sssssssd', $newStatus, $receivedByEmployee, $docno, $cdCode, $locationCode, $subId, $unit, $unitPrice);
    } elseif ($subId !== '') {
        $stmt->bind_param('ssssss', $newStatus, $receivedByEmployee, $docno, $cdCode, $locationCode, $subId);
    } elseif ($hasFullIdentity) {
        $stmt->bind_param('ssssssd', $newStatus, $receivedByEmployee, $docno, $cdCode, $locationCode, $unit, $unitPrice);
    } else {
        $stmt->bind_param('sssss', $newStatus, $receivedByEmployee, $docno, $cdCode, $locationCode);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($affectedRows !== 1) {
        throw new Exception('Batch item update did not affect exactly one row.');
    }
}

function execute_batch_non_received(
    mysqli $conn,
    array $item,
    string $newStatus,
    ?string $remark
): void {
    $docno = $item['docno'];
    $subId = $item['sub_id'];
    $cdCode = $item['cd_code'];
    $locationCode = $item['location_code'];
    $unit = $item['unit'];
    $unitPrice = $item['price'];
    $hasFullIdentity = $unit !== '' && $unitPrice !== null;

    $updateSql = 'UPDATE transfer_data_from_mssql
        SET delivery_status = ?,
            delivery_remark = ?,
            received_by_employee = NULL,
            received_qty_total = 0,
            received_count = 0
        WHERE docno = ? AND cd_code = ? AND location_code = ?';
    if ($subId !== '') {
        $updateSql .= ' AND sub_id = ?';
    }
    if ($hasFullIdentity) {
        $updateSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
    }

    $stmt = $conn->prepare($updateSql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    if ($subId !== '' && $hasFullIdentity) {
        $stmt->bind_param('sssssssd', $newStatus, $remark, $docno, $cdCode, $locationCode, $subId, $unit, $unitPrice);
    } elseif ($subId !== '') {
        $stmt->bind_param('ssssss', $newStatus, $remark, $docno, $cdCode, $locationCode, $subId);
    } elseif ($hasFullIdentity) {
        $stmt->bind_param('ssssssd', $newStatus, $remark, $docno, $cdCode, $locationCode, $unit, $unitPrice);
    } else {
        $stmt->bind_param('sssss', $newStatus, $remark, $docno, $cdCode, $locationCode);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($affectedRows !== 1) {
        throw new Exception('Batch item update did not affect exactly one row.');
    }
}

$conn = null;
$inTransaction = false;

try {
    if (!isset($_POST['new_status'], $_POST['items'])) {
        throw new Exception('Missing required parameters.');
    }

    $newStatus = trim((string)$_POST['new_status']);
    $allowedStatuses = [
        app_batch_status_received(),
        app_batch_status_postponed(),
        app_batch_status_cancelled(),
    ];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        throw new Exception('Invalid batch status.');
    }

    $items = parse_batch_items((string)$_POST['items']);
    $receivedByEmployee = isset($_POST['received_by_employee'])
        ? trim((string)$_POST['received_by_employee'])
        : null;
    $remark = isset($_POST['remark']) ? trim((string)$_POST['remark']) : null;

    if ($newStatus === app_batch_status_received() && ($receivedByEmployee === null || $receivedByEmployee === '')) {
        throw new Exception('Missing receiving employee.');
    }

    if ($newStatus === app_batch_status_postponed() && ($remark === null || $remark === '')) {
        throw new Exception('Missing postponement remark.');
    }

    $conn = app_db();
    if (!$conn->begin_transaction()) {
        throw new Exception('Failed to start transaction: ' . $conn->error);
    }
    $inTransaction = true;

    foreach ($items as $item) {
        if ($newStatus === app_batch_status_received()) {
            execute_batch_received($conn, $item, $receivedByEmployee);
        } else {
            $sharedRemark = $newStatus === app_batch_status_postponed() ? $remark : null;
            execute_batch_non_received($conn, $item, $newStatus, $sharedRemark);
        }
    }

    if (!$conn->commit()) {
        throw new Exception('Failed to commit transaction: ' . $conn->error);
    }
    $inTransaction = false;
    $conn->close();

    app_json_response([
        'success' => true,
        'message' => 'Batch status updated successfully.',
        'processed_count' => count($items),
    ]);
} catch (Throwable $e) {
    if ($conn instanceof mysqli) {
        if ($inTransaction) {
            $conn->rollback();
        }
        $conn->close();
    }

    app_error_response('Batch Update Error', 500, $e);
}
