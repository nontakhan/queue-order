<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$locationCode = isset($_POST['location_code']) ? trim($_POST['location_code']) : '';
if ($locationCode === '') {
    app_json_response(['success' => false, 'message' => 'กรุณาระบุ location_code'], 422);
}

$conn = app_db();

try {
    $result = app_execute($conn, 'SELECT sound_file FROM notification_sounds WHERE location_code = ?', 's', [$locationCode]);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $soundFile = (string) $row['sound_file'];
        $filePath = dirname(__DIR__) . '/sounds/' . $soundFile;
        if (strpos($soundFile, '/') !== false && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    $deleteResult = app_execute($conn, 'DELETE FROM notification_sounds WHERE location_code = ?', 's', [$locationCode]);
    if (!$deleteResult) {
        throw new Exception('ลบข้อมูลไม่สำเร็จ: ' . $conn->error);
    }

    $conn->close();
    app_json_response(['success' => true, 'message' => 'ลบเสียงแจ้งเตือนสำเร็จ']);
} catch (Exception $e) {
    $conn->close();
    app_error_response('Delete Error', 500, $e);
}
