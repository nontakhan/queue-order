<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$conn = app_db();
$locationCode = isset($_GET['location_code']) ? trim($_GET['location_code']) : '';

try {
    if ($locationCode !== '') {
        $escapedLoc = $conn->real_escape_string($locationCode);
        $sql = "SELECT ns.*, al.location_name AS location
                FROM notification_sounds ns
                LEFT JOIN app_locations al ON ns.location_code = al.location_code
                WHERE ns.location_code = '{$escapedLoc}'";
    } else {
        $sql = "SELECT ns.*, al.location_name AS location
                FROM notification_sounds ns
                LEFT JOIN app_locations al ON ns.location_code = al.location_code
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

    $conn->close();
    app_json_response(['success' => true, 'data' => $sounds]);
} catch (Exception $e) {
    $conn->close();
    app_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
