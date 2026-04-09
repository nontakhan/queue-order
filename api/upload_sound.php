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

if (!isset($_FILES['sound_file']) || $_FILES['sound_file']['error'] !== UPLOAD_ERR_OK) {
    app_json_response(['success' => false, 'message' => 'กรุณาเลือกไฟล์เสียง'], 422);
}

$file = $_FILES['sound_file'];
$allowedExts = ['mp3', 'wav', 'ogg'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExts, true)) {
    app_json_response(['success' => false, 'message' => 'รองรับเฉพาะไฟล์ .mp3, .wav, .ogg เท่านั้น'], 422);
}

if ($file['size'] > 5 * 1024 * 1024) {
    app_json_response(['success' => false, 'message' => 'ไฟล์ต้องมีขนาดไม่เกิน 5MB'], 422);
}

$soundsDir = dirname(__DIR__) . '/sounds/';
if (!is_dir($soundsDir) && !mkdir($soundsDir, 0775, true) && !is_dir($soundsDir)) {
    app_json_response(['success' => false, 'message' => 'ไม่สามารถสร้างโฟลเดอร์เสียงได้'], 500);
}

if (!is_writable($soundsDir)) {
    app_json_response(['success' => false, 'message' => 'โฟลเดอร์ sounds ไม่มีสิทธิ์เขียน'], 500);
}

$newFileName = 'sound_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationCode) . '_' . time() . '.' . $ext;
$destPath = $soundsDir . $newFileName;
$conn = app_db();

try {
    $escapedLoc = $conn->real_escape_string($locationCode);
    $oldResult = $conn->query("SELECT sound_file FROM notification_sounds WHERE location_code = '{$escapedLoc}'");
    if ($oldResult && $oldResult->num_rows > 0) {
        $oldRow = $oldResult->fetch_assoc();
        $oldFile = $soundsDir . $oldRow['sound_file'];
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('ไม่สามารถบันทึกไฟล์เสียงได้');
    }

    $escapedFile = $conn->real_escape_string($newFileName);
    $escapedOrigName = $conn->real_escape_string($file['name']);
    $sql = "INSERT INTO notification_sounds (location_code, sound_file, original_name)
            VALUES ('{$escapedLoc}', '{$escapedFile}', '{$escapedOrigName}')
            ON DUPLICATE KEY UPDATE sound_file = '{$escapedFile}', original_name = '{$escapedOrigName}', updated_at = CURRENT_TIMESTAMP";

    if (!$conn->query($sql)) {
        throw new Exception('บันทึกข้อมูลลง DB ไม่สำเร็จ: ' . $conn->error);
    }

    $conn->close();
    app_json_response(['success' => true, 'message' => 'อัปโหลดเสียงสำเร็จ', 'data' => ['location_code' => $locationCode, 'sound_file' => $newFileName, 'original_name' => $file['name']]]);
} catch (Exception $e) {
    $conn->close();
    app_json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
