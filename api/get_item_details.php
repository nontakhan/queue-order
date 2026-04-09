<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$conn = app_db();

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
    $conn->close();

    if (!$item) {
        app_json_response(['success' => false, 'message' => 'Item not found.']);
    }

    app_json_response(['success' => true, 'data' => $item]);
} catch (Exception $e) {
    $conn->close();
    app_json_response(['success' => false, 'message' => 'Query Error', 'error_detail' => $e->getMessage()], 500);
}
