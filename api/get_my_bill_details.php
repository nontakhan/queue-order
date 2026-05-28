<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$user = app_require_login(false);
$salesLname = trim((string) ($user['sales_lname'] ?? ''));

if ($salesLname === '') {
    app_json_response(['success' => false, 'message' => 'ยังไม่ได้ผูกชื่อพนักงานขายให้ผู้ใช้นี้'], 422);
}

$docno = isset($_GET['docno']) ? trim((string) $_GET['docno']) : '';
$cdCode = isset($_GET['cd_code']) ? trim((string) $_GET['cd_code']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$price = isset($_GET['price']) ? trim((string) $_GET['price']) : '';

if ($docno === '' || $cdCode === '' || $unit === '' || $price === '' || !is_numeric($price)) {
    app_json_response(['success' => false, 'message' => 'Missing required parameters'], 422);
}

$conn = app_db();
$stmt = null;
$historyStmt = null;

try {
    $sql = 'SELECT *
            FROM transfer_data_from_mssql
            WHERE user_lname = ? AND docno = ? AND cd_code = ? AND Lname_unit = ? AND UNITPRICE = ?
            LIMIT 1';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $priceValue = (float) $price;
    $stmt->bind_param('ssssd', $salesLname, $docno, $cdCode, $unit, $priceValue);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    $stmt = null;

    if (!$item) {
        app_json_response(['success' => false, 'message' => 'ไม่พบรายการของผู้ใช้นี้'], 404);
    }

    $historySql = 'SELECT id, received_qty, received_by_employee, received_at, note
                   FROM item_receive_history
                   WHERE docno = ? AND cd_code = ? AND lname_unit = ? AND unitprice = ?
                   ORDER BY received_at ASC, id ASC';
    $historyStmt = $conn->prepare($historySql);
    if ($historyStmt === false) {
        throw new Exception('History prepare failed: ' . $conn->error);
    }

    $historyStmt->bind_param('sssd', $docno, $cdCode, $unit, $priceValue);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    $item['receive_history'] = [];
    while ($historyRow = $historyResult->fetch_assoc()) {
        $item['receive_history'][] = $historyRow;
    }
    $historyStmt->close();
    $historyStmt = null;

    $conn->close();
    app_json_response(['success' => true, 'data' => $item]);
} catch (Exception $e) {
    if ($stmt) {
        $stmt->close();
    }
    if ($historyStmt) {
        $historyStmt->close();
    }
    $conn->close();
    app_error_response('Query Error', 500, $e);
}
