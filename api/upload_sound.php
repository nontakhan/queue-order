<?php
// api/upload_sound.php
// อัปโหลดไฟล์เสียงแจ้งเตือนสำหรับ location_code ที่กำหนด

ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$locationCode = isset($_POST['location_code']) ? trim($_POST['location_code']) : '';

if (empty($locationCode)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุ location_code']);
    exit;
}

if (!isset($_FILES['sound_file']) || $_FILES['sound_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเลือกไฟล์เสียง']);
    exit;
}

$file = $_FILES['sound_file'];
$allowedTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp3', 'audio/x-wav'];
$allowedExts = ['mp3', 'wav', 'ogg'];

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExts)) {
    echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะไฟล์ .mp3, .wav, .ogg เท่านั้น']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'ไฟล์ต้องมีขนาดไม่เกิน 5MB']);
    exit;
}

$soundsDir = dirname(__DIR__) . '/sounds/';
if (!is_dir($soundsDir)) {
    mkdir($soundsDir, 0755, true);
}

$newFileName = 'sound_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationCode) . '_' . time() . '.' . $ext;
$destPath = $soundsDir . $newFileName;

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database Connection Error']);
    exit;
}
$conn->set_charset("utf8");

try {
    // ลบไฟล์เสียงเก่าถ้ามี
    $escapedLoc = $conn->real_escape_string($locationCode);
    $oldResult = $conn->query("SELECT sound_file FROM notification_sounds WHERE location_code = '{$escapedLoc}'");
    if ($oldResult && $oldResult->num_rows > 0) {
        $oldRow = $oldResult->fetch_assoc();
        $oldFile = $soundsDir . $oldRow['sound_file'];
        if (file_exists($oldFile)) {
            unlink($oldFile);
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('ไม่สามารถบันทึกไฟล์ได้');
    }

    $escapedFile = $conn->real_escape_string($newFileName);
    $escapedOrigName = $conn->real_escape_string($file['name']);

    $sql = "INSERT INTO notification_sounds (location_code, sound_file, original_name) 
            VALUES ('{$escapedLoc}', '{$escapedFile}', '{$escapedOrigName}')
            ON DUPLICATE KEY UPDATE 
            sound_file = '{$escapedFile}', 
            original_name = '{$escapedOrigName}',
            updated_at = CURRENT_TIMESTAMP";

    if (!$conn->query($sql)) {
        throw new Exception('บันทึกข้อมูลลง DB ไม่สำเร็จ: ' . $conn->error);
    }

    echo json_encode([
        'success' => true, 
        'message' => 'อัปโหลดเสียงสำเร็จ',
        'data' => [
            'location_code' => $locationCode,
            'sound_file' => $newFileName,
            'original_name' => $file['name']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
