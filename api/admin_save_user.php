<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$id = (int) app_get_post('id', '0');
$username = app_get_post('username');
$fullName = app_get_post('full_name');
$role = app_get_post('role', 'user') === 'admin' ? 'admin' : 'user';
$defaultLocationCode = app_get_post('default_location_code', '');
$defaultLocationCode = $defaultLocationCode === '' ? null : strtoupper($defaultLocationCode);
$isActive = app_get_post('is_active', '1') === '1' ? 1 : 0;
$password = app_get_post('password', '');

if ($username === '' || $fullName === '') {
    app_json_response(['success' => false, 'message' => 'Username and full name are required'], 422);
}

$conn = app_db();

if ($id > 0) {
    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE app_users SET username = ?, full_name = ?, role = ?, default_location_code = ?, is_active = ?, password_hash = ? WHERE id = ?');
        $stmt->bind_param('ssssisi', $username, $fullName, $role, $defaultLocationCode, $isActive, $passwordHash, $id);
    } else {
        $stmt = $conn->prepare('UPDATE app_users SET username = ?, full_name = ?, role = ?, default_location_code = ?, is_active = ? WHERE id = ?');
        $stmt->bind_param('ssssii', $username, $fullName, $role, $defaultLocationCode, $isActive, $id);
    }
} else {
    if ($password === '') {
        $conn->close();
        app_json_response(['success' => false, 'message' => 'Password is required for new user'], 422);
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO app_users (username, password_hash, full_name, role, default_location_code, is_active) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssi', $username, $passwordHash, $fullName, $role, $defaultLocationCode, $isActive);
}

if (!$stmt->execute()) {
    $message = $conn->errno === 1062 ? 'Username already exists' : $stmt->error;
    $stmt->close();
    $conn->close();
    app_json_response(['success' => false, 'message' => $message], 422);
}

$stmt->close();
$conn->close();

app_json_response(['success' => true]);
