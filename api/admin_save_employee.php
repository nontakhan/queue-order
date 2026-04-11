<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$id = (int) app_get_post('id', '0');
$employeeName = app_get_post('employee_name');
$locationCode = strtoupper(app_get_post('location_code'));
$isActive = app_get_post('is_active', '1') === '1' ? 1 : 0;

if ($employeeName === '' || $locationCode === '') {
    app_json_response(['success' => false, 'message' => 'Employee name and location are required'], 422);
}

$conn = app_db();

$locationStmt = $conn->prepare('SELECT 1 FROM app_locations WHERE location_code = ? LIMIT 1');
$locationStmt->bind_param('s', $locationCode);
$locationStmt->execute();
$locationResult = $locationStmt->get_result();
if ($locationResult->num_rows === 0) {
    $locationStmt->close();
    $conn->close();
    app_json_response(['success' => false, 'message' => 'Selected location does not exist'], 422);
}
$locationStmt->close();

if ($id > 0) {
    $stmt = $conn->prepare('UPDATE app_employees SET employee_name = ?, location_code = ?, is_active = ? WHERE id = ?');
    $stmt->bind_param('ssii', $employeeName, $locationCode, $isActive, $id);
} else {
    $stmt = $conn->prepare('INSERT INTO app_employees (employee_name, location_code, is_active) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $employeeName, $locationCode, $isActive);
}

if (!$stmt->execute()) {
    $message = $stmt->error ?: 'Unable to save employee';
    $stmt->close();
    $conn->close();
    app_json_response(['success' => false, 'message' => $message], 422);
}

$stmt->close();
$conn->close();

app_json_response(['success' => true]);
