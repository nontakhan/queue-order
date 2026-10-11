<?php

ini_set('display_errors', 0);
require_once __DIR__ . '/_deleted_items.php';
app_require_login(true);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}
$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/', $id)) {
    app_json_response(['success' => false, 'message' => 'รหัสรายการไม่ถูกต้อง'], 422);
}
$conn = app_db();
try {
    app_ensure_deleted_items_table($conn);
    $row = app_execute($conn, 'SELECT * FROM deleted_transfer_items WHERE id = ?', 's', [$id])->fetch_assoc();
    if (!$row) {
        $conn->close();
        app_json_response(['success' => false, 'message' => 'ไม่พบรายการที่ลบ'], 404);
    }
    $snapshot = json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    unset($row['snapshot_json']);
    $conn->close();
    app_json_response(['success' => true, 'data' => [
        'item' => $snapshot['item'], 'receive_history' => $snapshot['receive_history'] ?? [], 'deletion' => $row,
    ]]);
} catch (Throwable $error) {
    $conn->close();
    app_error_response('โหลดรายละเอียดรายการที่ลบไม่สำเร็จ', 500, $error);
}
