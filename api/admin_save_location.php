<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$id = (int) app_get_post('id', '0');
$locationCode = strtoupper(app_get_post('location_code'));
$locationName = app_get_post('location_name');
$isActive = app_get_post('is_active', '1') === '1' ? 1 : 0;

if ($locationCode === '' || $locationName === '') {
    app_json_response(['success' => false, 'message' => 'Location code and location name are required'], 422);
}

$conn = app_db();

if ($id > 0) {
    $stmt = $conn->prepare('UPDATE app_locations SET location_code = ?, location_name = ?, is_active = ? WHERE id = ?');
    $stmt->bind_param('ssii', $locationCode, $locationName, $isActive, $id);
} else {
    $stmt = $conn->prepare('INSERT INTO app_locations (location_code, location_name, is_active) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $locationCode, $locationName, $isActive);
}

if (!$stmt->execute()) {
    $message = $conn->errno === 1062 ? 'Location code already exists' : $stmt->error;
    $stmt->close();
    $conn->close();
    app_json_response(['success' => false, 'message' => $message], 422);
}

$stmt->close();
$conn->close();

app_json_response(['success' => true]);

