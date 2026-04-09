<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

$conn = app_db();
$result = $conn->query('SELECT id, location_code, location_name, is_active, created_at, updated_at FROM app_locations ORDER BY location_code ASC');
$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['is_active'] = (int) $row['is_active'];
    $rows[] = $row;
}
$conn->close();

app_json_response(['success' => true, 'data' => $rows]);

