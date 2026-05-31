<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$user = app_require_login(false);
$isAdmin = ($user['role'] ?? '') === 'admin';
$salesLname = trim((string) ($user['sales_lname'] ?? ''));

if (!$isAdmin && $salesLname === '') {
    app_json_response([
        'success' => false,
        'message' => 'ยังไม่ได้ผูกชื่อพนักงานขายให้ผู้ใช้นี้',
    ], 422);
}

$conn = app_db();

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min((int) $_GET['limit'], 100)) : 20;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$includeAllStatus = isset($_GET['include_all_status']) && $_GET['include_all_status'] === '1';
$startDate = isset($_GET['startDate']) ? trim($_GET['startDate']) : '';
$endDate = isset($_GET['endDate']) ? trim($_GET['endDate']) : '';

$items = [];
$totalPages = 0;
$totalItems = 0;

try {
    $whereClauses = [];
    $params = [];
    $types = '';

    if (!$isAdmin) {
        $whereClauses[] = 'user_lname = ?';
        $params[] = $salesLname;
        $types .= 's';
    }

    if (!$includeAllStatus && $status !== '') {
        $whereClauses[] = 'delivery_status = ?';
        $params[] = $status;
        $types .= 's';
    } elseif (!$includeAllStatus) {
        $whereClauses[] = "(delivery_status IS NULL OR delivery_status = '' OR delivery_status = 'รับบางส่วน')";
    }

    if ($searchTerm !== '') {
        $whereClauses[] = '(docno LIKE ? OR custname LIKE ? OR cd_name LIKE ?)';
        $searchPattern = '%' . $searchTerm . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $types .= 'sss';
    }

    if ($startDate !== '' && $endDate !== '') {
        $whereClauses[] = 'docdate BETWEEN ? AND ?';
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= 'ss';
    } elseif ($startDate !== '') {
        $whereClauses[] = 'docdate >= ?';
        $params[] = $startDate;
        $types .= 's';
    } elseif ($endDate !== '') {
        $whereClauses[] = 'docdate <= ?';
        $params[] = $endDate;
        $types .= 's';
    }

    $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $countResult = app_execute($conn, 'SELECT COUNT(*) AS total FROM transfer_data_from_mssql ' . $whereSql, $types, $params);
    $totalItems = (int) $countResult->fetch_assoc()['total'];
    $totalPages = $totalItems > 0 ? (int) ceil($totalItems / $limit) : 0;

    if ($totalItems > 0) {
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $limit;
        $dataParams = $params;
        $dataTypes = $types . 'ii';
        $dataParams[] = $limit;
        $dataParams[] = $offset;

        $dataSql = "SELECT docno, docdate, custname AS customer_name, user_lname, cd_code,
                       cd_name, qty, Lname_unit AS unit, REMARK, UNITPRICE, NETAMOUNT,
                       branch, shipflag, location_code, location,
                       delivery_status, delivery_remark, received_by_employee, last_update,
                       COALESCE(received_qty_total, 0) AS received_qty_total,
                       COALESCE(received_count, 0) AS received_count
                    FROM transfer_data_from_mssql {$whereSql}
                    ORDER BY last_update DESC, docdate DESC, docno DESC
                    LIMIT ? OFFSET ?";

        $result = app_execute($conn, $dataSql, $dataTypes, $dataParams);
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
} catch (Exception $e) {
    $conn->close();
    app_error_response('Query Error', 500, $e);
}

$conn->close();

app_json_response([
    'success' => true,
    'sales_lname' => $isAdmin ? 'ทุกพนักงานขาย' : $salesLname,
    'is_admin' => $isAdmin,
    'data' => $items,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $totalPages,
        'items_per_page' => $limit,
        'total_items' => $totalItems,
    ],
]);
