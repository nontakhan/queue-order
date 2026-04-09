<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

$conn = app_db();
$sql = 'SELECT id, username, full_name, role, is_active, default_location_code, created_at, updated_at FROM app_users ORDER BY username ASC';
$result = $conn->query($sql);
$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['is_active'] = (int) $row['is_active'];
    $rows[] = $row;
}
$conn->close();

app_json_response(['success' => true, 'data' => $rows]);

