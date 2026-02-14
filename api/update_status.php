<?php
// api/update_status.php (เวอร์ชันอัปเดต - Cleaned)

ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once dirname(__DIR__) . '/db_config.php';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Connection Error']);
    exit;
}
$conn->set_charset("utf8");

$error = null;
$success_message = '';

try {
    if (!isset($_POST['docno']) || !isset($_POST['cd_code']) || !isset($_POST['new_status'])) {
        throw new Exception("Missing required parameters.");
    }

    $docno = $_POST['docno'];
    $cd_code = $_POST['cd_code'];
    $newStatus = $_POST['new_status'];
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : null;

    // --- สร้าง SQL Query แบบ Dynamic ---
    // **แก้ไข: นำการอัปเดต delivery_date ออก**
    $setClauses = ["delivery_status = ?"];
    $params = [$newStatus];
    $types = "s";

    if (!empty($remark)) {
        $setClauses[] = "delivery_remark = ?";
        $params[] = $remark;
        $types .= "s";
    }

    $params[] = $docno;
    $params[] = $cd_code;
    $types .= "ss";

    $sql = "UPDATE transfer_data_from_mssql SET " . implode(', ', $setClauses) . " WHERE docno = ? AND cd_code = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) { throw new Exception("Failed to prepare statement: " . $conn->error); }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $success_message = 'Status updated successfully.';
    } else {
        $success_message = 'No rows updated. Item might not exist or status is already the same.';
    }

    $stmt->close();

} catch (Exception $e) {
    $error = $e->getMessage();
}

$conn->close();

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update Error', 'error_detail' => $error]);
} else {
    echo json_encode(['success' => true, 'message' => $success_message]);
}
?>
