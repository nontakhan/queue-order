<?php

require_once dirname(__DIR__) . '/db_config.php';

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function app_db_profiles(): array
{
    $profilesFile = dirname(__DIR__) . '/db_profiles.php';
    if (is_file($profilesFile)) {
        $profiles = require $profilesFile;
        if (is_array($profiles) && count($profiles) > 0) {
            return $profiles;
        }
    }

    global $db_host, $db_user, $db_pass, $db_name;

    return [
        'default' => [
            'label' => 'Default',
            'host' => $db_host,
            'user' => $db_user,
            'pass' => $db_pass,
            'name' => $db_name,
        ],
    ];
}

function app_default_db_profile_key(): string
{
    $profiles = app_db_profiles();
    return (string) array_key_first($profiles);
}

function app_current_db_profile_key(): string
{
    app_start_session();
    $profiles = app_db_profiles();
    $selectedKey = $_SESSION['db_profile'] ?? null;

    if (is_string($selectedKey) && isset($profiles[$selectedKey])) {
        return $selectedKey;
    }

    $defaultKey = app_default_db_profile_key();
    $_SESSION['db_profile'] = $defaultKey;
    return $defaultKey;
}

function app_current_db_profile(): array
{
    $profiles = app_db_profiles();
    $key = app_current_db_profile_key();
    return $profiles[$key];
}

function app_select_db_profile(string $key): array
{
    app_start_session();
    $profiles = app_db_profiles();

    if (!isset($profiles[$key])) {
        app_json_response(['success' => false, 'message' => 'Invalid database profile'], 422);
    }

    $_SESSION['db_profile'] = $key;
    return $profiles[$key];
}

function app_db_profile_payload(string $key, array $profile): array
{
    return [
        'key' => $key,
        'label' => $profile['label'] ?? $key,
        'name' => $profile['name'] ?? '',
    ];
}

function app_db(): mysqli
{
    $profile = app_current_db_profile();
    $db_host = $profile['host'] ?? '';
    $db_user = $profile['user'] ?? '';
    $db_pass = $profile['pass'] ?? '';
    $db_name = $profile['name'] ?? '';

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        app_json_response(['success' => false, 'message' => 'Database Connection Error'], 500);
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function app_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function app_is_debug(): bool
{
    return getenv('APP_DEBUG') === '1';
}

function app_error_response(string $message, int $statusCode = 500, ?Throwable $error = null): void
{
    if ($error) {
        error_log($error->getMessage());
    }

    $payload = ['success' => false, 'message' => $message];
    if (app_is_debug() && $error) {
        $payload['error_detail'] = $error->getMessage();
    }

    app_json_response($payload, $statusCode);
}

function app_execute(mysqli $conn, string $sql, string $types = '', array $params = [])
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }

    $result = $stmt->get_result();
    if ($result === false && $stmt->errno === 0) {
        $stmt->close();
        return true;
    }

    $stmt->close();
    return $result;
}

function app_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    if ($stmt === false) {
        throw new Exception('Failed to prepare column check: ' . $conn->error);
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (int) $result->fetch_assoc()['total'] > 0;
    $stmt->close();
    return $exists;
}

function app_ensure_user_bill_access_column(mysqli $conn): void
{
    if (app_column_exists($conn, 'app_users', 'can_view_all_bills')) {
        return;
    }

    if (!$conn->query('ALTER TABLE app_users ADD COLUMN can_view_all_bills TINYINT(1) NOT NULL DEFAULT 0 AFTER sales_lname')) {
        throw new Exception('Failed to add app_users.can_view_all_bills: ' . $conn->error);
    }
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

function app_can_view_all_bills(array $user): bool
{
    return ($user['role'] ?? '') === 'admin' || (int) ($user['can_view_all_bills'] ?? 0) === 1;
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
        'sales_lname' => $row['sales_lname'] ?? null,
        'can_view_all_bills' => (int) ($row['can_view_all_bills'] ?? 0),
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
