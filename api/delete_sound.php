<?php
// api/delete_sound.php
// ลบเสียงแจ้งเตือนของ location_code ที่กำหนด

ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$locationCode = isset($_POST['location_code']) ? trim($_POST['location_code']) : '';

if (empty($locationCode)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุ location_code']);
    exit;
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database Connection Error']);
    exit;
}
$conn->set_charset("utf8");

try {
    $escapedLoc = $conn->real_escape_string($locationCode);
    
    // ดึงชื่อไฟล์เก่าเพื่อลบ
    $result = $conn->query("SELECT sound_file FROM notification_sounds WHERE location_code = '{$escapedLoc}'");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $soundsDir = dirname(__DIR__) . '/sounds/';
        $filePath = $soundsDir . $row['sound_file'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    $deleteResult = $conn->query("DELETE FROM notification_sounds WHERE location_code = '{$escapedLoc}'");
    if (!$deleteResult) {
        throw new Exception('ลบข้อมูลไม่สำเร็จ: ' . $conn->error);
    }

    if ($conn->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'ลบเสียงแจ้งเตือนสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'message' => 'ไม่พบเสียงแจ้งเตือนสำหรับคลังนี้']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
