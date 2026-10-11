<?php

ini_set('display_errors', 0);
require_once __DIR__ . '/_deleted_items.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$user = app_require_login(false);
$canViewAllBills = app_can_view_all_bills($user);
if (!$canViewAllBills && trim((string) ($user['sales_lname'] ?? '')) === '') {
    app_json_response(['success' => false, 'message' => 'ยังไม่ได้ผูกชื่อพนักงานขายให้ผู้ใช้นี้'], 422);
}

$rawItems = $_POST['items'] ?? '';
$items = is_string($rawItems) ? json_decode($rawItems, true) : null;
if (!is_array($items)) {
    app_json_response(['success' => false, 'message' => 'กรุณาเลือกรายการที่ต้องการลบ'], 422);
}

$conn = app_db();
try {
    $deleted = app_archive_cancelled_items($conn, $items, $user, true);
    $conn->close();
    app_json_response(['success' => true, 'deleted' => $deleted, 'message' => 'ย้ายไปเก็บในรายการที่ลบแล้ว']);
} catch (InvalidArgumentException | DomainException $error) {
    $conn->close();
    app_json_response(['success' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    $conn->close();
    app_error_response('ลบรายการไม่สำเร็จ กรุณาลองใหม่', 500, $error);
}
