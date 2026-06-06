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
$salesLname = app_get_post('sales_lname', '');
$salesLname = $salesLname === '' ? null : $salesLname;
$canViewAllBills = app_get_post('can_view_all_bills', '0') === '1' ? 1 : 0;
$isActive = app_get_post('is_active', '1') === '1' ? 1 : 0;
$password = app_get_post('password', '');

if ($username === '' || $fullName === '') {
    app_json_response(['success' => false, 'message' => 'Username and full name are required'], 422);
}

$conn = app_db();

try {
    app_ensure_user_bill_access_column($conn);

    if ($id > 0) {
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE app_users SET username = ?, full_name = ?, role = ?, default_location_code = ?, sales_lname = ?, can_view_all_bills = ?, is_active = ?, password_hash = ? WHERE id = ?');
            if ($stmt === false) {
                throw new Exception($conn->error);
            }
            $stmt->bind_param('sssssiisi', $username, $fullName, $role, $defaultLocationCode, $salesLname, $canViewAllBills, $isActive, $passwordHash, $id);
        } else {
            $stmt = $conn->prepare('UPDATE app_users SET username = ?, full_name = ?, role = ?, default_location_code = ?, sales_lname = ?, can_view_all_bills = ?, is_active = ? WHERE id = ?');
            if ($stmt === false) {
                throw new Exception($conn->error);
            }
            $stmt->bind_param('sssssiii', $username, $fullName, $role, $defaultLocationCode, $salesLname, $canViewAllBills, $isActive, $id);
        }
    } else {
        if ($password === '') {
            $conn->close();
            app_json_response(['success' => false, 'message' => 'Password is required for new user'], 422);
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO app_users (username, password_hash, full_name, role, default_location_code, sales_lname, can_view_all_bills, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt === false) {
            throw new Exception($conn->error);
        }
        $stmt->bind_param('ssssssii', $username, $passwordHash, $fullName, $role, $defaultLocationCode, $salesLname, $canViewAllBills, $isActive);
    }

    if (!$stmt->execute()) {
        $message = $conn->errno === 1062 ? 'Username already exists' : $stmt->error;
        $stmt->close();
        $conn->close();
        app_json_response(['success' => false, 'message' => $message], 422);
    }

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    app_error_response('Save User Error', 500, $e);
}

app_json_response(['success' => true]);
