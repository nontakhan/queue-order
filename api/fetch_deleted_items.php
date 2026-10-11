<?php

ini_set('display_errors', 0);
require_once __DIR__ . '/_deleted_items.php';
app_require_login(true);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$where = [];
$params = [];
foreach (['startDate', 'endDate'] as $key) {
    $value = $_GET[$key] ?? '';
    if ($value === '') {
        continue;
    }
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
        app_json_response(['success' => false, 'message' => 'วันที่ไม่ถูกต้อง'], 422);
    }
    $where[] = $key === 'startDate' ? 'deleted_at >= ?' : 'deleted_at < ?';
    $params[] = $key === 'startDate' ? $value . ' 00:00:00'
        : (new DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d 00:00:00');
}
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$location = isset($_GET['location_code']) && is_string($_GET['location_code']) ? trim($_GET['location_code']) : '';
if ($search !== '') {
    $where[] = '(docno LIKE ? OR customer_name LIKE ? OR cd_code LIKE ? OR cd_name LIKE ? OR deleted_by_name LIKE ?)';
    foreach (range(1, 5) as $ignored) {
        $params[] = '%' . $search . '%';
    }
}
if ($location !== '') {
    $where[] = "FIND_IN_SET(?, REPLACE(location_code, ' ', '')) > 0";
    $params[] = $location;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$types = str_repeat('s', count($params));
$conn = app_db();
try {
    app_ensure_deleted_items_table($conn);
    $total = (int) app_execute($conn, 'SELECT COUNT(*) FROM deleted_transfer_items' . $whereSql, $types, $params)->fetch_row()[0];
    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $page = $totalPages > 0 ? min($page, $totalPages) : 1;
    $result = app_execute($conn, 'SELECT * FROM deleted_transfer_items' . $whereSql . ' ORDER BY deleted_at DESC, id DESC LIMIT ? OFFSET ?',
        $types . 'ii', array_merge($params, [$limit, ($page - 1) * $limit]));
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $snapshot = json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $fields = array_change_key_case($snapshot['item'], CASE_LOWER);
        $row['qty'] = $fields['qty'] ?? null;
        $row['unit'] = $fields['lname_unit'] ?? null;
        $row['docdate'] = $fields['docdate'] ?? null;
        unset($row['snapshot_json']);
        $items[] = $row;
    }
    // Use archived warehouse values so administrators can also find inactive warehouses.
    $locations = app_execute($conn, "SELECT DISTINCT location_code, location FROM deleted_transfer_items
        WHERE location_code IS NOT NULL AND location_code <> '' ORDER BY location_code")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    app_json_response(['success' => true, 'data' => $items, 'locations' => $locations, 'pagination' => [
        'current_page' => $page, 'total_pages' => $totalPages, 'items_per_page' => $limit, 'total_items' => $total,
    ]]);
} catch (Throwable $error) {
    $conn->close();
    app_error_response('โหลดรายการที่ลบไม่สำเร็จ', 500, $error);
}
