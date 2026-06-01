<?php

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php';

$user = app_current_user();
$conn = app_db();

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min((int) $_GET['limit'], 5000)) : 15;
$offset = 0;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$locationCode = ($user && !empty($user['default_location_code'])) ? $user['default_location_code'] : (isset($_GET['location']) ? trim($_GET['location']) : '');
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$customerName = isset($_GET['customer_name']) ? trim($_GET['customer_name']) : '';
$customerKeyword = isset($_GET['customer_keyword']) ? trim($_GET['customer_keyword']) : '';
$includeExcludedCustomer = isset($_GET['include_excluded_customer']) && $_GET['include_excluded_customer'] === '1';
$includeAllStatus = isset($_GET['include_all_status']) && $_GET['include_all_status'] === '1';
$startDate = isset($_GET['startDate']) ? trim($_GET['startDate']) : '';
$endDate = isset($_GET['endDate']) ? trim($_GET['endDate']) : '';
$excludedCustomerKeyword = 'เธเธณเธฃเธธเนเธเน€เธเธซเธฐเธ เธฑเธ“เธ‘เนเธชเธณเธเธฑเธเธเธฒเธเนเธซเธเน';
$normalizedCustomerExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(custname), ' ', ''), '(', ''), ')', ''), 'ใ€€', ''), '.', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '')";
$normalizedLocationExpr = "REPLACE(TRIM(location_code), ' ', '')";

$items = [];
$error = null;
$totalPages = 0;
$totalItems = 0;

try {
    $whereClauses = [];
    $params = [];
    $types = '';

    if (!$includeAllStatus && $status !== '') {
        $whereClauses[] = 'delivery_status = ?';
        $params[] = $status;
        $types .= 's';
    } elseif (!$includeAllStatus) {
        $whereClauses[] = "(delivery_status IS NULL OR delivery_status = '' OR delivery_status = 'รับบางส่วน')";
    }

    if ($locationCode !== '') {
        $whereClauses[] = "FIND_IN_SET(?, {$normalizedLocationExpr}) > 0";
        $params[] = $locationCode;
        $types .= 's';
    }

    if ($customerName !== '') {
        $normalizedCustomerName = preg_replace('/[()\s]+/u', '', $customerName);
        $whereClauses[] = "{$normalizedCustomerExpr} = ?";
        $params[] = $normalizedCustomerName;
        $types .= 's';
    } elseif ($customerKeyword !== '') {
        $normalizedCustomerKeyword = preg_replace('/[()\s]+/u', '', $customerKeyword);
        $whereClauses[] = "{$normalizedCustomerExpr} LIKE ?";
        $params[] = '%' . $normalizedCustomerKeyword . '%';
        $types .= 's';
    } elseif (!$includeExcludedCustomer) {
        $normalizedExcludedCustomerKeyword = preg_replace('/[()\s]+/u', '', $excludedCustomerKeyword);
        $whereClauses[] = "(custname IS NULL OR {$normalizedCustomerExpr} NOT LIKE ?)";
        $params[] = '%' . $normalizedExcludedCustomerKeyword . '%';
        $types .= 's';
    }

    if ($searchTerm !== '') {
        $whereClauses[] = '(docno LIKE ? OR custname LIKE ?)';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
        $types .= 'ss';
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

    $countSql = 'SELECT COUNT(*) as total FROM transfer_data_from_mssql ' . $whereSql;
    $countResult = app_execute($conn, $countSql, $types, $params);
    if ($countResult === false) {
        throw new Exception('Count Query failed.');
    }

    $totalItems = (int) $countResult->fetch_assoc()['total'];
    $totalPages = $totalItems > 0 ? (int) ceil($totalItems / $limit) : 0;

    if ($totalItems > 0) {
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $limit;
        $dataParams = $params;
        $dataTypes = $types . 'ii';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $dataSql = "SELECT docno, docdate, custname AS customer_name, cd_code,
                       cd_name, qty, Lname_unit AS unit, REMARK, UNITPRICE, branch, shipflag,
                       location_code, location,
                       delivery_status, delivery_remark, received_by_employee, last_update, create_at,
                       COALESCE(received_qty_total, 0) AS received_qty_total,
                       COALESCE(received_count, 0) AS received_count
                    FROM transfer_data_from_mssql {$whereSql}
                    ORDER BY last_update DESC, docdate DESC, docno DESC
                    LIMIT ? OFFSET ?";

        $result = app_execute($conn, $dataSql, $dataTypes, $dataParams);
        if ($result === false) {
            throw new Exception('Data Query failed.');
        }

        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$conn->close();

if ($error) {
    app_error_response('Query Error', 500, new Exception($error));
}

app_json_response([
    'success' => true,
    'data' => $items,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $totalPages,
        'items_per_page' => $limit,
        'total_items' => $totalItems,
    ],
]);
