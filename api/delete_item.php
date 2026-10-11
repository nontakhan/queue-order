<?php

ini_set('display_errors', 0);
require_once __DIR__ . '/_deleted_items.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$conn = app_db();
try {
    $deleted = app_archive_cancelled_items($conn, [$_POST], app_current_user());
    $conn->close();
    app_json_response(['success' => true, 'deleted' => $deleted, 'message' => 'ย้ายไปเก็บในรายการที่ลบแล้ว']);
} catch (InvalidArgumentException | DomainException $error) {
    $conn->close();
    app_json_response(['success' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    $conn->close();
    app_error_response('ลบรายการไม่สำเร็จ กรุณาลองใหม่', 500, $error);
}
