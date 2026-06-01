<?php

require_once __DIR__ . '/api/_bootstrap.php';

app_require_login(true);

header('Content-Type: text/html; charset=utf-8');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function transfer_column_exists(mysqli $conn, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'transfer_data_from_mssql'
          AND COLUMN_NAME = ?
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $column);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Column check failed: ' . $error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function setup_transfer_create_at(mysqli $conn): array
{
    $messages = [];

    if (!transfer_column_exists($conn, 'create_at')) {
        if (!$conn->query("
            ALTER TABLE transfer_data_from_mssql
            ADD COLUMN create_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            AFTER last_update
        ")) {
            throw new Exception('Add create_at failed: ' . $conn->error);
        }
        $messages[] = 'Added transfer_data_from_mssql.create_at';
        if (!$conn->query("
            UPDATE transfer_data_from_mssql
            SET create_at = COALESCE(last_update, create_at, NOW())
        ")) {
            throw new Exception('Backfill create_at failed: ' . $conn->error);
        }
        $messages[] = 'Backfilled existing rows from last_update';
    } else {
        $messages[] = 'transfer_data_from_mssql.create_at already exists';
        if (!$conn->query("
            UPDATE transfer_data_from_mssql
            SET create_at = COALESCE(last_update, NOW())
            WHERE create_at IS NULL
               OR create_at = '0000-00-00 00:00:00'
        ")) {
            throw new Exception('Backfill create_at failed: ' . $conn->error);
        }
        $messages[] = 'Backfilled empty create_at values';
    }

    return $messages;
}

$profiles = app_db_profiles();
$results = [];

foreach ($profiles as $key => $profile) {
    $label = (string) ($profile['label'] ?? $key);
    $conn = null;

    try {
        $conn = new mysqli(
            (string) ($profile['host'] ?? ''),
            (string) ($profile['user'] ?? ''),
            (string) ($profile['pass'] ?? ''),
            (string) ($profile['name'] ?? ''),
            (int) ($profile['port'] ?? 3306)
        );

        if ($conn->connect_error) {
            throw new Exception($conn->connect_error);
        }

        $conn->set_charset('utf8mb4');
        $results[] = [
            'label' => $label,
            'ok' => true,
            'messages' => setup_transfer_create_at($conn),
        ];
    } catch (Exception $e) {
        $results[] = [
            'label' => $label,
            'ok' => false,
            'messages' => [$e->getMessage()],
        ];
    } finally {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup transfer create_at</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 p-6 text-slate-900">
    <main class="mx-auto max-w-3xl rounded-lg bg-white p-6 shadow">
        <h1 class="text-2xl font-bold">Setup transfer create_at</h1>
        <div class="mt-6 space-y-4">
            <?php foreach ($results as $result): ?>
                <section class="rounded-lg border p-4 <?php echo $result['ok'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'; ?>">
                    <h2 class="font-bold"><?php echo h($result['label']); ?></h2>
                    <ul class="mt-2 list-disc pl-5 text-sm">
                        <?php foreach ($result['messages'] as $message): ?>
                            <li><?php echo h($message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
