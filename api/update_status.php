<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
$conn = app_db();

try {
    if (!isset($_POST['docno'], $_POST['cd_code'], $_POST['new_status'])) {
        throw new Exception('Missing required parameters.');
    }

    $docno = $_POST['docno'];
    $cdCode = $_POST['cd_code'];
    $newStatus = $_POST['new_status'];
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : null;
    $receivedByEmployee = isset($_POST['received_by_employee']) ? trim($_POST['received_by_employee']) : null;

    if ($newStatus === 'รับแล้ว' && ($receivedByEmployee === null || $receivedByEmployee === '')) {
        throw new Exception('Missing receiving employee.');
    }

    $setClauses = ['delivery_status = ?'];
    $params = [$newStatus];
    $types = 's';

    if ($newStatus === 'เลื่อน' && $remark !== null && $remark !== '') {
        $setClauses[] = 'delivery_remark = ?';
        $params[] = $remark;
        $types .= 's';
    } elseif ($newStatus !== 'เลื่อน') {
        $setClauses[] = 'delivery_remark = NULL';
    }

    if ($newStatus === 'รับแล้ว') {
        $setClauses[] = 'received_by_employee = ?';
        $params[] = $receivedByEmployee;
        $types .= 's';
    } else {
        $setClauses[] = 'received_by_employee = NULL';
    }

    $params[] = $docno;
    $params[] = $cdCode;
    $types .= 'ss';

    $sql = 'UPDATE transfer_data_from_mssql SET ' . implode(', ', $setClauses) . ' WHERE docno = ? AND cd_code = ?';
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
} catch (Exception $e) {
    $conn->close();
    app_json_response(['success' => false, 'message' => 'Update Error', 'error_detail' => $e->getMessage()], 500);
}
