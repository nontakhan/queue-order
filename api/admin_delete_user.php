<?php

require_once __DIR__ . '/_bootstrap.php';

$currentUser = app_require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$id = (int) app_get_post('id', '0');
if ($id <= 0) {
    app_json_response(['success' => false, 'message' => 'Invalid user id'], 422);
}

if ((int) ($currentUser['id'] ?? 0) === $id) {
    app_json_response(['success' => false, 'message' => 'Cannot delete the current logged-in user'], 422);
}

$conn = app_db();
$stmt = $conn->prepare('DELETE FROM app_users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();
$conn->close();

app_json_response(['success' => true]);
