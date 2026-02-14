<?php
// api/check_new_items.php
// ตรวจสอบจำนวนรายการรอตรวจรับสำหรับ location_code ที่กำหนด
// ใช้สำหรับเปรียบเทียบกับจำนวนเดิมเพื่อเล่นเสียงแจ้งเตือน

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

$locationCode = isset($_GET['location_code']) ? trim($_GET['location_code']) : '';

if (empty($locationCode)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุ location_code']);
    exit;
}

$lastKnownCount = isset($_GET['last_count']) ? (int)$_GET['last_count'] : -1;

try {
    $escapedLoc = $conn->real_escape_string($locationCode);
    
    // นับจำนวนรายการที่รอตรวจรับ (delivery_status เป็น NULL หรือว่าง)
    $sql = "SELECT COUNT(*) as total_pending 
            FROM transfer_data_from_mssql 
            WHERE TRIM(location_code) = '{$escapedLoc}' 
            AND (delivery_status IS NULL OR delivery_status = '')";
    
    $result = $conn->query($sql);
    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $row = $result->fetch_assoc();
    $totalPending = (int)$row['total_pending'];

    // ถ้ามีรายการใหม่มากกว่าเดิม ให้ดึงรายการล่าสุดมาแสดง
    $newItems = [];
    if ($lastKnownCount >= 0 && $totalPending > $lastKnownCount) {
        $diff = $totalPending - $lastKnownCount;
        $limitItems = min($diff, 10); // แสดงสูงสุด 10 รายการ
        $sqlNew = "SELECT docno, docdate, custname, cd_name, qty, Lname_unit
                   FROM transfer_data_from_mssql 
                   WHERE TRIM(location_code) = '{$escapedLoc}' 
                   AND (delivery_status IS NULL OR delivery_status = '')
                   ORDER BY last_update DESC, docdate DESC
                   LIMIT {$limitItems}";
        $newResult = $conn->query($sqlNew);
        if ($newResult) {
            while ($newRow = $newResult->fetch_assoc()) {
                $newItems[] = $newRow;
            }
        }
    }
    
    echo json_encode([
        'success' => true, 
        'data' => [
            'location_code' => $locationCode,
            'total_pending' => $totalPending,
            'new_items' => $newItems
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
