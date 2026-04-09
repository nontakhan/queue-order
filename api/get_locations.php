<?php

require_once __DIR__ . '/_bootstrap.php';

$user = app_current_user();
$conn = app_db();
$locations = [];

if ($user && !empty($user['default_location_code'])) {
    $stmt = $conn->prepare('SELECT location_code, location_name AS location FROM app_locations WHERE is_active = 1 AND location_code = ? ORDER BY location_code ASC');
    $stmt->bind_param('s', $user['default_location_code']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query('SELECT location_code, location_name AS location FROM app_locations WHERE is_active = 1 ORDER BY location_code ASC');
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
}

$conn->close();

app_json_response(['success' => true, 'data' => $locations]);
