<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
$conn = app_db();

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

    return (float)$normalized;
}

function parse_optional_price(?string $value): ?float
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $normalized = str_replace(',', '', trim($value));
    if (!is_numeric($normalized)) {
        throw new Exception('Invalid price.');
    }

    return (float)$normalized;
}

function handle_receive_status_update(
    mysqli $conn,
    string $docno,
    string $cdCode,
    string $unit,
    ?float $unitPrice,
    string $receivedByEmployee,
    ?float $receivedQty
): void {
    $conn->begin_transaction();

    try {
        $hasFullIdentity = $unit !== '' && $unitPrice !== null;
        $selectSql = 'SELECT qty, COALESCE(received_qty_total, 0) AS received_qty_total
            FROM transfer_data_from_mssql
            WHERE docno = ? AND cd_code = ?';
        if ($hasFullIdentity) {
            $selectSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
        }
        $selectSql .= ' FOR UPDATE';

        $stmt = $conn->prepare($selectSql);
        if ($stmt === false) {
            throw new Exception('Failed to prepare statement: ' . $conn->error);
        }

        if ($hasFullIdentity) {
            $stmt->bind_param('sssd', $docno, $cdCode, $unit, $unitPrice);
        } else {
            $stmt->bind_param('ss', $docno, $cdCode);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new Exception('Item not found.');
        }

        $totalQty = (float)$row['qty'];
        $current = (float)$row['received_qty_total'];
        $remaining = max(0, $totalQty - $current);
        $qtyToReceive = $receivedQty ?? $remaining;

        if ($qtyToReceive <= 0) {
            throw new Exception('Received quantity must be greater than zero.');
        }

        if ($qtyToReceive > $remaining + 0.0001) {
            throw new Exception('Received quantity cannot exceed remaining quantity.');
        }

        $newReceivedQty = $current + $qtyToReceive;
        $newStatus = $newReceivedQty + 0.0001 >= $totalQty
            ? app_status_received()
            : app_status_partial_received();

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
            $stmt->bind_param('sssdds', $docno, $cdCode, $unit, $unitPrice, $qtyToReceive, $receivedByEmployee);
        } else {
            $stmt->bind_param('ssds', $docno, $cdCode, $qtyToReceive, $receivedByEmployee);
        }
        $stmt->execute();
        $stmt->close();

        $updateSql = 'UPDATE transfer_data_from_mssql
            SET delivery_status = ?,
                delivery_remark = NULL,
                received_by_employee = ?,
                received_qty_total = ?,
                received_count = received_count + 1
            WHERE docno = ? AND cd_code = ?';
        if ($hasFullIdentity) {
            $updateSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
        }

        $stmt = $conn->prepare($updateSql);
        if ($stmt === false) {
            throw new Exception('Failed to prepare statement: ' . $conn->error);
        }

        if ($hasFullIdentity) {
            $stmt->bind_param('ssdsssd', $newStatus, $receivedByEmployee, $newReceivedQty, $docno, $cdCode, $unit, $unitPrice);
        } else {
            $stmt->bind_param('ssdss', $newStatus, $receivedByEmployee, $newReceivedQty, $docno, $cdCode);
        }
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

try {
    if (!isset($_POST['docno'], $_POST['cd_code'], $_POST['new_status'])) {
        throw new Exception('Missing required parameters.');
    }

    $docno = $_POST['docno'];
    $cdCode = $_POST['cd_code'];
    $newStatus = $_POST['new_status'];
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : null;
    $receivedByEmployee = isset($_POST['received_by_employee']) ? trim($_POST['received_by_employee']) : null;
    $receivedQty = parse_receive_quantity($_POST['received_qty'] ?? null);
    $unit = isset($_POST['unit']) ? trim((string)$_POST['unit']) : '';
    $unitPrice = parse_optional_price($_POST['price'] ?? null);

    if ($newStatus === app_status_received()) {
        if ($receivedByEmployee === null || $receivedByEmployee === '') {
            throw new Exception('Missing receiving employee.');
        }

        handle_receive_status_update($conn, $docno, $cdCode, $unit, $unitPrice, $receivedByEmployee, $receivedQty);
        $conn->close();

        app_json_response(['success' => true, 'message' => 'Receive status updated successfully.']);
    }

    $setClauses = ['delivery_status = ?'];
    $params = [$newStatus];
    $types = 's';

    if ($newStatus === app_status_postponed() && $remark !== null && $remark !== '') {
        $setClauses[] = 'delivery_remark = ?';
        $params[] = $remark;
        $types .= 's';
    } elseif ($newStatus !== app_status_postponed()) {
        $setClauses[] = 'delivery_remark = NULL';
    }

    $setClauses[] = 'received_by_employee = NULL';

    if ($newStatus !== app_status_received() && $newStatus !== app_status_partial_received()) {
        $setClauses[] = 'received_qty_total = 0';
        $setClauses[] = 'received_count = 0';
    }

    $whereClauses = ['docno = ?', 'cd_code = ?'];
    $params[] = $docno;
    $params[] = $cdCode;
    $types .= 'ss';

    if ($unit !== '' && $unitPrice !== null) {
        $whereClauses[] = 'Lname_unit = ?';
        $whereClauses[] = 'UNITPRICE = ?';
        $params[] = $unit;
        $params[] = $unitPrice;
        $types .= 'sd';
    }

    $sql = 'UPDATE transfer_data_from_mssql SET ' . implode(', ', $setClauses) . ' WHERE ' . implode(' AND ', $whereClauses);
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $message = $stmt->affected_rows > 0 ? 'Status updated successfully.' : 'No rows updated. Item might not exist or status is already the same.';
    $stmt->close();
    $conn->close();

    app_json_response(['success' => true, 'message' => $message]);
} catch (Throwable $e) {
    $conn->close();
    app_error_response('Update Error', 500, $e);
}
