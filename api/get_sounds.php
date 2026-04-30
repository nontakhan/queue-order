<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$conn = app_db();
$locationCode = isset($_GET['location_code']) ? trim($_GET['location_code']) : '';

try {
    if ($locationCode !== '') {
        $sql = "SELECT ns.*, al.location_name AS location
                FROM notification_sounds ns
                LEFT JOIN app_locations al ON ns.location_code = al.location_code
                WHERE ns.location_code = ?";
        $result = app_execute($conn, $sql, 's', [$locationCode]);
    } else {
        $sql = "SELECT ns.*, al.location_name AS location
                FROM notification_sounds ns
                LEFT JOIN app_locations al ON ns.location_code = al.location_code
                ORDER BY ns.location_code ASC";
        $result = app_execute($conn, $sql);
    }

    if ($result === false) {
        throw new Exception('Query failed.');
    }

    $sounds = [];
    while ($row = $result->fetch_assoc()) {
        $sounds[] = $row;
    }

    $conn->close();
    app_json_response(['success' => true, 'data' => $sounds]);
} catch (Exception $e) {
    $conn->close();
    app_error_response('Query Error', 500, $e);
}
