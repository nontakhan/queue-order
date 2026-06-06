<?php

require_once __DIR__ . '/api/_bootstrap.php';

app_require_login(true);

$messages = [];

function setup_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (int) $result->fetch_assoc()['total'] > 0;
    $stmt->close();
    return $exists;
}

function setup_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (int) $result->fetch_assoc()['total'] > 0;
    $stmt->close();
    return $exists;
}

function setup_connect_profile(array $profile): mysqli
{
    $conn = new mysqli($profile['host'] ?? '', $profile['user'] ?? '', $profile['pass'] ?? '', $profile['name'] ?? '');
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function setup_run_employee_login_updates(mysqli $conn, array &$messages, string $label): void
{
    if (!setup_column_exists($conn, 'app_users', 'sales_lname')) {
        if (!$conn->query("ALTER TABLE app_users ADD COLUMN sales_lname VARCHAR(255) NULL AFTER default_location_code")) {
            throw new Exception($conn->error);
        }
        $messages[] = "{$label}: เพิ่มคอลัมน์ app_users.sales_lname แล้ว";
    } else {
        $messages[] = "{$label}: มีคอลัมน์ app_users.sales_lname อยู่แล้ว";
    }

    if (!setup_index_exists($conn, 'app_users', 'idx_app_users_sales_lname')) {
        if (!$conn->query('CREATE INDEX idx_app_users_sales_lname ON app_users (sales_lname)')) {
            throw new Exception($conn->error);
        }
        $messages[] = "{$label}: เพิ่ม index app_users.sales_lname แล้ว";
    } else {
        $messages[] = "{$label}: มี index app_users.sales_lname อยู่แล้ว";
    }

    if (!setup_column_exists($conn, 'app_users', 'can_view_all_bills')) {
        if (!$conn->query('ALTER TABLE app_users ADD COLUMN can_view_all_bills TINYINT(1) NOT NULL DEFAULT 0 AFTER sales_lname')) {
            throw new Exception($conn->error);
        }
        $messages[] = "{$label}: เพิ่มคอลัมน์ app_users.can_view_all_bills แล้ว";
    } else {
        $messages[] = "{$label}: มีคอลัมน์ app_users.can_view_all_bills อยู่แล้ว";
    }

    if (!setup_index_exists($conn, 'transfer_data_from_mssql', 'idx_transfer_user_lname_last_update')) {
        if (!$conn->query('CREATE INDEX idx_transfer_user_lname_last_update ON transfer_data_from_mssql (user_lname, last_update)')) {
            throw new Exception($conn->error);
        }
        $messages[] = "{$label}: เพิ่ม index transfer_data_from_mssql(user_lname, last_update) แล้ว";
    } else {
        $messages[] = "{$label}: มี index transfer_data_from_mssql(user_lname, last_update) อยู่แล้ว";
    }
}

try {
    foreach (app_db_profiles() as $profile) {
        $conn = null;
        $label = (string) ($profile['label'] ?? $profile['name'] ?? 'Database');
        try {
            $conn = setup_connect_profile($profile);
            setup_run_employee_login_updates($conn, $messages, $label);
        } finally {
            if ($conn instanceof mysqli) {
                $conn->close();
            }
        }
    }
} catch (Exception $e) {
    $messages[] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Employee Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 p-6 text-slate-900">
    <main class="mx-auto max-w-2xl rounded-2xl bg-white p-6 shadow">
        <h1 class="text-2xl font-bold">ตั้งค่าระบบ Login พนักงาน</h1>
        <ul class="mt-4 space-y-2">
            <?php foreach ($messages as $message): ?>
                <li class="rounded-xl bg-slate-100 px-4 py-3"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
        <a href="admin.html" class="mt-6 inline-flex rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white">กลับหน้าจัดการ</a>
    </main>
</body>
</html>
