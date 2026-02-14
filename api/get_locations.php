<?php
// api/get_locations.php
// ไฟล์นี้ทำหน้าที่ดึงข้อมูล location_code และ location (ชื่อเต็ม) ที่ไม่ซ้ำกัน

ini_set('display_errors', 1);
error_reporting(E_ALL);

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

$locations = [];
$error = null;

try {
    // **แก้ไข Query ให้ดึงมาทั้ง location_code และ location (ชื่อเต็ม)**
    // ใช้ GROUP BY เพื่อให้ได้ข้อมูลที่ไม่ซ้ำกัน
    $sql = "SELECT location_code, location 
            FROM transfer_data_from_mssql 
            WHERE location_code IS NOT NULL AND location_code != '' AND location IS NOT NULL AND location != ''
            GROUP BY location_code, location
            ORDER BY location_code ASC";
            
    $result = $conn->query($sql);
    
    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }
    
    // ส่งกลับไปเป็น Array ของ Object ที่มีทั้ง location_code และ location
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

$conn->close();

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query Error', 'error_detail' => $error]);
} else {
    echo json_encode(['success' => true, 'data' => $locations]);
}
?>
