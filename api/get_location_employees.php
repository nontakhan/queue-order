<?php

require_once __DIR__ . '/_bootstrap.php';

$locationCode = strtoupper(trim($_GET['location_code'] ?? ''));

if ($locationCode === '') {
    app_json_response(['success' => false, 'message' => 'Location code is required'], 422);
}

$conn = app_db();
$stmt = $conn->prepare(
    'SELECT ae.id, ae.employee_name, ae.location_code, ae.is_active, ae.created_at, ae.updated_at,
            al.location_name
     FROM app_employees ae
     LEFT JOIN app_locations al ON al.location_code = ae.location_code
     WHERE ae.location_code = ? AND ae.is_active = 1
     ORDER BY ae.employee_name ASC'
);

if ($stmt === false) {
    $conn->close();
    app_json_response(['success' => false, 'message' => 'Unable to prepare employee query'], 500);
}

$stmt->bind_param('s', $locationCode);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = app_employee_payload($row);
}

$stmt->close();
$conn->close();

app_json_response(['success' => true, 'data' => $rows]);
