<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$docno = isset($_POST['docno']) ? trim($_POST['docno']) : '';
$subId = isset($_POST['sub_id']) ? trim((string) $_POST['sub_id']) : '';
$cdCode = isset($_POST['cd_code']) ? trim($_POST['cd_code']) : '';
$locationCode = isset($_POST['location_code']) ? trim($_POST['location_code']) : '';
$unit = isset($_POST['unit']) ? trim($_POST['unit']) : '';
$price = isset($_POST['price']) ? trim($_POST['price']) : '';

if ($docno === '' || $cdCode === '' || $locationCode === '') {
    app_json_response(['success' => false, 'message' => 'docno, cd_code, and location_code are required'], 422);
}

if ($price !== '' && !is_numeric($price)) {
    app_json_response(['success' => false, 'message' => 'price must be numeric'], 422);
}

$conn = app_db();

try {
    $whereSql = 'docno = ? AND cd_code = ? AND location_code = ?';
    if ($subId !== '') {
        $whereSql .= ' AND sub_id = ?';
    }
    if ($unit !== '' && $price !== '') {
        $whereSql .= ' AND Lname_unit = ? AND UNITPRICE = ?';
    }

    $stmt = $conn->prepare('DELETE FROM transfer_data_from_mssql WHERE ' . $whereSql);
    if ($stmt === false) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }

    if ($unit !== '' && $price !== '') {
        $priceValue = (float) $price;
        if ($subId !== '') {
            $stmt->bind_param('sssssd', $docno, $cdCode, $locationCode, $subId, $unit, $priceValue);
        } else {
            $stmt->bind_param('ssssd', $docno, $cdCode, $locationCode, $unit, $priceValue);
        }
    } elseif ($subId !== '') {
        $stmt->bind_param('ssss', $docno, $cdCode, $locationCode, $subId);
    } else {
        $stmt->bind_param('sss', $docno, $cdCode, $locationCode);
    }
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new Exception('ไม่พบรายการที่ต้องการลบ');
    }

    $stmt->close();
    $conn->close();
    app_json_response(['success' => true, 'message' => 'ลบรายการสำเร็จ']);
} catch (Exception $e) {
    $conn->close();
    app_error_response('Delete Error', 500, $e);
}
