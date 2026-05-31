<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$user = app_require_login(false);
$isAdmin = ($user['role'] ?? '') === 'admin';
$salesLname = trim((string) ($user['sales_lname'] ?? ''));

if (!$isAdmin && $salesLname === '') {
    app_json_response(['success' => false, 'message' => 'ยังไม่ได้ผูกชื่อพนักงานขายให้ผู้ใช้นี้'], 422);
}

$rawItems = $_POST['items'] ?? '';
$items = json_decode((string) $rawItems, true);

if (!is_array($items) || count($items) === 0) {
    app_json_response(['success' => false, 'message' => 'กรุณาเลือกรายการที่ต้องการลบ'], 422);
}

if (count($items) > 100) {
    app_json_response(['success' => false, 'message' => 'ลบได้ครั้งละไม่เกิน 100 รายการ'], 422);
}

$conn = app_db();
$stmt = null;
$deleted = 0;
$inTransaction = false;

try {
    $stmt = $conn->prepare('DELETE FROM transfer_data_from_mssql WHERE user_lname = ? AND docno = ? AND cd_code = ? AND Lname_unit = ? AND UNITPRICE = ?');
    if ($stmt === false) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }

    $conn->begin_transaction();
    $inTransaction = true;

    foreach ($items as $item) {
        $docno = trim((string) ($item['docno'] ?? ''));
        $cdCode = trim((string) ($item['cd_code'] ?? ''));
        $unit = trim((string) ($item['unit'] ?? ''));
        $price = trim((string) ($item['price'] ?? ''));
        $ownerSalesLname = trim((string) ($item['user_lname'] ?? ''));

        if ($docno === '' || $cdCode === '' || $unit === '' || $price === '' || !is_numeric($price)) {
            throw new Exception('ข้อมูลรายการไม่ครบถ้วน');
        }

        if ($isAdmin && $ownerSalesLname === '') {
            throw new Exception('Missing owner parameter');
        }

        $priceValue = (float) $price;
        $targetSalesLname = $isAdmin ? $ownerSalesLname : $salesLname;
        $stmt->bind_param('ssssd', $targetSalesLname, $docno, $cdCode, $unit, $priceValue);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $deleted += max(0, $stmt->affected_rows);
    }

    if ($deleted === 0) {
        throw new Exception('ไม่พบรายการที่ลบได้');
    }

    $conn->commit();
    $inTransaction = false;
    $stmt->close();
    $conn->close();
    app_json_response(['success' => true, 'deleted' => $deleted]);
} catch (Exception $e) {
    if ($inTransaction) {
        $conn->rollback();
    }
    if ($stmt) {
        $stmt->close();
    }
    $conn->close();
    app_error_response('Delete Error', 500, $e);
}
