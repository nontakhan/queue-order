<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$username = app_get_post('username');
$password = app_get_post('password');

if ($username === '' || $password === '') {
    app_json_response(['success' => false, 'message' => 'Username and password are required'], 422);
}

$conn = app_db();
$stmt = $conn->prepare('SELECT * FROM app_users WHERE username = ? AND is_active = 1 LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row || !password_verify($password, $row['password_hash'])) {
    app_json_response(['success' => false, 'message' => 'Invalid username or password'], 401);
}

app_start_session();
session_regenerate_id(true);
$_SESSION['user'] = app_user_payload($row);

app_json_response(['success' => true, 'user' => $_SESSION['user']]);

