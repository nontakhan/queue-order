<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

$conn = app_db();
try {
    app_ensure_user_bill_access_column($conn);
    $sql = 'SELECT id, username, full_name, role, is_active, default_location_code, sales_lname, can_view_all_bills, created_at, updated_at FROM app_users ORDER BY username ASC';
    $result = $conn->query($sql);
    if ($result === false) {
        throw new Exception($conn->error);
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $row['can_view_all_bills'] = (int) ($row['can_view_all_bills'] ?? 0);
        $rows[] = $row;
    }
} catch (Exception $e) {
    $conn->close();
    app_error_response('Load Users Error', 500, $e);
}
$conn->close();

app_json_response(['success' => true, 'data' => $rows]);
