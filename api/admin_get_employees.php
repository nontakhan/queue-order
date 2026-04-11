<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

$conn = app_db();
$sql = "SELECT ae.id, ae.employee_name, ae.location_code, ae.is_active, ae.created_at, ae.updated_at,
               al.location_name
        FROM app_employees ae
        LEFT JOIN app_locations al ON al.location_code = ae.location_code
        ORDER BY ae.employee_name ASC";
$result = $conn->query($sql);
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = app_employee_payload($row);
}
$conn->close();

app_json_response(['success' => true, 'data' => $rows]);
