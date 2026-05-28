<?php

require_once __DIR__ . '/_bootstrap.php';

app_require_login(true);

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min((int) $_GET['limit'], 50)) : 30;

$conn = app_db();
$names = [];

try {
    if ($query !== '') {
        $sql = "SELECT DISTINCT user_lname
                FROM transfer_data_from_mssql
                WHERE user_lname IS NOT NULL
                  AND user_lname <> ''
                  AND user_lname LIKE ?
                ORDER BY user_lname ASC
                LIMIT ?";
        $result = app_execute($conn, $sql, 'si', ['%' . $query . '%', $limit]);
    } else {
        $sql = "SELECT DISTINCT user_lname
                FROM transfer_data_from_mssql
                WHERE user_lname IS NOT NULL
                  AND user_lname <> ''
                ORDER BY user_lname ASC
                LIMIT ?";
        $result = app_execute($conn, $sql, 'i', [$limit]);
    }

    while ($row = $result->fetch_assoc()) {
        $name = trim((string) $row['user_lname']);
        if ($name !== '') {
            $names[] = ['id' => $name, 'text' => $name];
        }
    }
} catch (Exception $e) {
    $conn->close();
    app_error_response('Query Error', 500, $e);
}

$conn->close();

app_json_response(['success' => true, 'results' => $names]);
