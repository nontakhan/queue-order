<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$conn = app_db();
$stmt = null;
$historyStmt = null;

try {
    if (!isset($_GET['docno'], $_GET['cd_code'], $_GET['unit'], $_GET['price'])) {
        throw new Exception('Missing required parameters for details lookup.');
    }

    $sql = 'SELECT * FROM transfer_data_from_mssql WHERE docno = ? AND cd_code = ? AND Lname_unit = ? AND UNITPRICE = ?';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('sssd', $_GET['docno'], $_GET['cd_code'], $_GET['unit'], $_GET['price']);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    $stmt = null;

    if (!$item) {
        $conn->close();
        $conn = null;
        app_json_response(['success' => false, 'message' => 'Item not found.']);
    }

    $historySql = 'SELECT id, received_qty, received_by_employee, received_at, note
                   FROM item_receive_history
                   WHERE docno = ? AND cd_code = ?
                   ORDER BY received_at ASC, id ASC';
    $historyStmt = $conn->prepare($historySql);
    if ($historyStmt === false) {
        throw new Exception('History prepare failed: ' . $conn->error);
    }

    $historyStmt->bind_param('ss', $_GET['docno'], $_GET['cd_code']);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    $item['receive_history'] = [];
    while ($historyRow = $historyResult->fetch_assoc()) {
        $item['receive_history'][] = $historyRow;
    }
    $historyStmt->close();
    $historyStmt = null;
    $conn->close();
    $conn = null;

    app_json_response(['success' => true, 'data' => $item]);
} catch (Exception $e) {
    if ($stmt) {
        $stmt->close();
    }
    if ($historyStmt) {
        $historyStmt->close();
    }
    if ($conn) {
        $conn->close();
    }
    app_error_response('Query Error', 500, $e);
}
