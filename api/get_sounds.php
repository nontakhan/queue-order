<?php
// api/get_sounds.php
// ดึงข้อมูลเสียงแจ้งเตือนทั้งหมด หรือตาม location_code

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

try {
    if (!empty($locationCode)) {
        $escapedLoc = $conn->real_escape_string($locationCode);
        $sql = "SELECT ns.*, t.location 
                FROM notification_sounds ns
                LEFT JOIN (
                    SELECT location_code, location 
                    FROM transfer_data_from_mssql 
                    WHERE location_code IS NOT NULL AND location_code != '' 
                    GROUP BY location_code, location
                ) t ON ns.location_code = t.location_code
                WHERE ns.location_code = '{$escapedLoc}'";
    } else {
        $sql = "SELECT ns.*, t.location 
                FROM notification_sounds ns
                LEFT JOIN (
                    SELECT location_code, location 
                    FROM transfer_data_from_mssql 
                    WHERE location_code IS NOT NULL AND location_code != '' 
                    GROUP BY location_code, location
                ) t ON ns.location_code = t.location_code
                ORDER BY ns.location_code ASC";
    }

    $result = $conn->query($sql);
    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $sounds = [];
    while ($row = $result->fetch_assoc()) {
        $sounds[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $sounds]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
