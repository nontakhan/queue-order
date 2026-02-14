<?php
// api/delete_item.php

ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once dirname(__DIR__) . '/db_config.php';

// ตรวจสอบว่าเป็น POST request เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Connection Error']);
    exit;
}
$conn->set_charset("utf8");

// --- รับค่า Parameters จาก POST ---
$docno = isset($_POST['docno']) ? trim($_POST['docno']) : '';
$cd_code = isset($_POST['cd_code']) ? trim($_POST['cd_code']) : '';

// --- ตรวจสอบความถูกต้องของข้อมูลที่รับมา ---
if (empty($docno) || empty($cd_code)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน (docno and cd_code are required)']);
    exit;
}

$error = null;
$success = false;

try {
    // --- สร้าง SQL Query โดยใช้ Prepared Statement เพื่อความปลอดภัย ---
    $sql = "DELETE FROM transfer_data_from_mssql WHERE docno = ? AND cd_code = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    // --- Bind Parameters ---
    $stmt->bind_param("ss", $docno, $cd_code);
    
    // --- Execute Query ---
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $success = true;
        } else {
            throw new Exception('ไม่พบรายการที่ต้องการลบ');
        }
    } else {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

$conn->close();

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query Error', 'error_detail' => $error]);
} else {
    echo json_encode(['success' => true, 'message' => 'ลบรายการสำเร็จ']);
}
?>
