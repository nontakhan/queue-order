<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$docno = isset($_POST['docno']) ? trim($_POST['docno']) : '';
$cdCode = isset($_POST['cd_code']) ? trim($_POST['cd_code']) : '';

if ($docno === '' || $cdCode === '') {
    app_json_response(['success' => false, 'message' => 'docno and cd_code are required'], 422);
}

$conn = app_db();

try {
    $stmt = $conn->prepare('DELETE FROM transfer_data_from_mssql WHERE docno = ? AND cd_code = ?');
    if ($stmt === false) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }

    $stmt->bind_param('ss', $docno, $cdCode);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        throw new Exception('ไม่พบรายการที่ต้องการลบ');
    }

    $stmt->close();
    $conn->close();
    app_json_response(['success' => true, 'message' => 'ลบรายการสำเร็จ']);
} catch (Exception $e) {
    $conn->close();
    app_json_response(['success' => false, 'message' => 'Query Error', 'error_detail' => $e->getMessage()], 500);
}
