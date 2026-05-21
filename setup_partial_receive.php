<?php

require_once __DIR__ . '/api/_bootstrap.php';

app_require_login(true);

header('Content-Type: text/html; charset=utf-8');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('ไม่สามารถเตรียมคำสั่งตรวจสอบคอลัมน์ได้: ' . $conn->error);
    }

    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('ไม่สามารถตรวจสอบคอลัมน์ได้: ' . $error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function execute_sql(mysqli $conn, string $sql): void
{
    if (!$conn->query($sql)) {
        throw new Exception($conn->error);
    }
}

function add_column_if_missing(mysqli $conn, string $table, string $column, string $definition, string $afterColumn): bool
{
    if (column_exists($conn, $table, $column)) {
        return false;
    }

    execute_sql($conn, "
        ALTER TABLE {$table}
        ADD COLUMN {$column} {$definition}
        AFTER {$afterColumn}
    ");

    return true;
}

function setup_partial_receive_schema(mysqli $conn): array
{
    $messages = [];

    execute_sql($conn, "
        CREATE TABLE IF NOT EXISTS item_receive_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            docno VARCHAR(100) NOT NULL,
            cd_code VARCHAR(100) NOT NULL,
            lname_unit VARCHAR(100) NULL,
            unitprice DECIMAL(15,4) NULL,
            received_qty DECIMAL(15,3) NOT NULL,
            received_by_employee VARCHAR(255) NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            note VARCHAR(500),
            INDEX idx_receive_history_item (docno, cd_code),
            INDEX idx_receive_history_received_at (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'ตาราง item_receive_history พร้อมใช้งาน';

    if (add_column_if_missing($conn, 'item_receive_history', 'lname_unit', 'VARCHAR(100) NULL', 'cd_code')) {
        $messages[] = 'Added item_receive_history.lname_unit';
    } else {
        $messages[] = 'item_receive_history.lname_unit already exists';
    }

    if (add_column_if_missing($conn, 'item_receive_history', 'unitprice', 'DECIMAL(15,4) NULL', 'lname_unit')) {
        $messages[] = 'Added item_receive_history.unitprice';
    } else {
        $messages[] = 'item_receive_history.unitprice already exists';
    }

    if (add_column_if_missing($conn, 'transfer_data_from_mssql', 'received_by_employee', 'VARCHAR(255) NULL', 'delivery_remark')) {
        $messages[] = 'เพิ่มคอลัมน์ received_by_employee แล้ว';
    } else {
        $messages[] = 'คอลัมน์ received_by_employee มีอยู่แล้ว';
    }

    if (add_column_if_missing($conn, 'transfer_data_from_mssql', 'received_qty_total', 'DECIMAL(15,3) NOT NULL DEFAULT 0', 'received_by_employee')) {
        $messages[] = 'เพิ่มคอลัมน์ received_qty_total แล้ว';
    } else {
        $messages[] = 'คอลัมน์ received_qty_total มีอยู่แล้ว';
    }

    if (add_column_if_missing($conn, 'transfer_data_from_mssql', 'received_count', 'INT NOT NULL DEFAULT 0', 'received_qty_total')) {
        $messages[] = 'เพิ่มคอลัมน์ received_count แล้ว';
    } else {
        $messages[] = 'คอลัมน์ received_count มีอยู่แล้ว';
    }

    execute_sql($conn, "
        UPDATE transfer_data_from_mssql
        SET received_qty_total = COALESCE(qty, 0),
            received_count = 1
        WHERE delivery_status = 'รับแล้ว'
          AND COALESCE(received_qty_total, 0) = 0
          AND COALESCE(received_count, 0) = 0
    ");
    $messages[] = 'Backfill ข้อมูลเก่าที่รับแล้วเรียบร้อย';

    return $messages;
}

$profiles = app_db_profiles();
$results = [];

foreach ($profiles as $key => $profile) {
    $label = (string) ($profile['label'] ?? $key);
    $dbName = (string) ($profile['name'] ?? '');
    $conn = null;

    try {
        $conn = new mysqli(
            (string) ($profile['host'] ?? ''),
            (string) ($profile['user'] ?? ''),
            (string) ($profile['pass'] ?? ''),
            $dbName
        );

        if ($conn->connect_error) {
            throw new Exception('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $conn->connect_error);
        }

        if (!$conn->set_charset('utf8mb4')) {
            throw new Exception('ตั้งค่า charset utf8mb4 ไม่สำเร็จ: ' . $conn->error);
        }

        $messages = setup_partial_receive_schema($conn);
        $results[] = [
            'key' => (string) $key,
            'label' => $label,
            'database' => $dbName,
            'status' => 'ready',
            'messages' => $messages,
        ];
    } catch (Throwable $error) {
        $results[] = [
            'key' => (string) $key,
            'label' => $label,
            'database' => $dbName,
            'status' => 'failed',
            'messages' => [$error->getMessage()],
        ];
    } finally {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
}

$allReady = array_reduce($results, static function (bool $ready, array $result): bool {
    return $ready && $result['status'] === 'ready';
}, true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่า Partial Receive</title>
    <style>
        body {
            background: #f3f4f6;
            color: #111827;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 32px;
        }

        .container {
            margin: 0 auto;
            max-width: 960px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            margin-bottom: 16px;
            padding: 20px;
        }

        h1 {
            font-size: 28px;
            margin: 0 0 8px;
        }

        h2 {
            font-size: 18px;
            margin: 0 0 12px;
        }

        .summary {
            border-left: 4px solid <?= $allReady ? '#16a34a' : '#dc2626' ?>;
        }

        .status {
            border-radius: 999px;
            display: inline-block;
            font-size: 13px;
            font-weight: bold;
            padding: 4px 10px;
        }

        .ready {
            background: #dcfce7;
            color: #166534;
        }

        .failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .meta {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }

        ul {
            margin: 12px 0 0;
            padding-left: 24px;
        }

        li {
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <main class="container">
        <section class="panel summary">
            <h1>ตั้งค่า Partial Receive Schema</h1>
            <p class="meta">
                ผลลัพธ์รวม:
                <strong><?= $allReady ? 'พร้อมใช้งานทุกโปรไฟล์' : 'มีบางโปรไฟล์ตั้งค่าไม่สำเร็จ' ?></strong>
            </p>
        </section>

        <?php foreach ($results as $result): ?>
            <section class="panel">
                <h2>
                    <?= h($result['label']) ?>
                    <span class="status <?= h($result['status']) ?>">
                        <?= $result['status'] === 'ready' ? 'พร้อมใช้งาน' : 'ไม่สำเร็จ' ?>
                    </span>
                </h2>
                <p class="meta">
                    Profile: <?= h($result['key']) ?> |
                    Database: <?= h($result['database']) ?>
                </p>
                <ul>
                    <?php foreach ($result['messages'] as $message): ?>
                        <li><?= h((string) $message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
