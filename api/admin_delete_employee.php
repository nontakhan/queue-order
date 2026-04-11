<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$id = (int) app_get_post('id', '0');
if ($id <= 0) {
    app_json_response(['success' => false, 'message' => 'Invalid employee id'], 422);
}

$conn = app_db();
$stmt = $conn->prepare('DELETE FROM app_employees WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();
$conn->close();

app_json_response(['success' => true]);
