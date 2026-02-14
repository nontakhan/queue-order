<?php
// api/get_item_details.php
// ทำหน้าที่ดึงข้อมูลทั้งหมดของ 1 รายการตาม Primary Key ที่ส่งมา

ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/db_config.php';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Connection Error']);
    exit;
}
$conn->set_charset("utf8");

$error = null;
$item_details = null;

try {
    // ตรวจสอบว่าได้รับ Primary Key ครบทั้ง 4 ส่วนหรือไม่
    if (!isset($_GET['docno']) || !isset($_GET['cd_code']) || !isset($_GET['unit']) || !isset($_GET['price'])) {
        throw new Exception("Missing required parameters for details lookup.");
    }

    // ใช้ Prepared Statement เพื่อความปลอดภัยสูงสุด
    $sql = "SELECT * FROM transfer_data_from_mssql 
            WHERE docno = ? AND cd_code = ? AND Lname_unit = ? AND UNITPRICE = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        "sssd", // s = string, d = double/decimal
        $_GET['docno'],
        $_GET['cd_code'],
        $_GET['unit'],
        $_GET['price']
    );

    $stmt->execute();
    $result = $stmt->get_result();
    $item_details = $result->fetch_assoc(); // ดึงข้อมูลแค่แถวเดียว

    $stmt->close();

} catch (Exception $e) {
    $error = $e->getMessage();
}

$conn->close();

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query Error', 'error_detail' => $error]);
} else {
    if ($item_details) {
        echo json_encode(['success' => true, 'data' => $item_details]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
    }
}
?>
