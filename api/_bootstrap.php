<?php

require_once dirname(__DIR__) . '/db_config.php';

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function app_db(): mysqli
{
    global $db_host, $db_user, $db_pass, $db_name;

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        app_json_response(['success' => false, 'message' => 'Database Connection Error'], 500);
    }

    $conn->set_charset('utf8');
    return $conn;
}

function app_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function app_current_user(): ?array
{
    app_start_session();
    return $_SESSION['user'] ?? null;
}

function app_require_login(bool $adminOnly = false): array
{
    $user = app_current_user();

    if (!$user) {
        app_json_response(['success' => false, 'message' => 'Unauthorized'], 401);
    }

    if ($adminOnly && ($user['role'] ?? '') !== 'admin') {
        app_json_response(['success' => false, 'message' => 'Forbidden'], 403);
    }

    return $user;
}

function app_get_post(string $key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function app_user_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'full_name' => $row['full_name'],
        'role' => $row['role'],
        'is_active' => (int) $row['is_active'],
        'default_location_code' => $row['default_location_code'] ?? null,
    ];
}

function app_employee_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'employee_name' => $row['employee_name'],
        'location_code' => $row['location_code'],
        'location_name' => $row['location_name'] ?? null,
        'is_active' => (int) $row['is_active'],
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}
