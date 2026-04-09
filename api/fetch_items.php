<?php

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php';

$user = app_current_user();
$conn = app_db();

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min((int) $_GET['limit'], 5000)) : 15;
$offset = ($page - 1) * $limit;
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

$items = [];
$error = null;
$totalPages = 0;
$totalItems = 0;
$debugInfo = [];

try {
    $whereClauses = [];

    if (!$includeAllStatus && $status !== '') {
        $escapedStatus = $conn->real_escape_string($status);
        $whereClauses[] = "delivery_status = '{$escapedStatus}'";
    } elseif (!$includeAllStatus) {
        $whereClauses[] = "(delivery_status IS NULL OR delivery_status = '')";
    }

    if ($locationCode !== '') {
        $escapedLocation = $conn->real_escape_string($locationCode);
        $whereClauses[] = "TRIM(location_code) = '{$escapedLocation}'";
    }

    if ($customerName !== '') {
        $escapedCustomerName = $conn->real_escape_string($customerName);
        $normalizedCustomerName = preg_replace('/[()\s]+/u', '', $escapedCustomerName);
        $whereClauses[] = "{$normalizedCustomerExpr} = '{$normalizedCustomerName}'";
    } elseif ($customerKeyword !== '') {
        $escapedCustomerKeyword = $conn->real_escape_string($customerKeyword);
        $normalizedCustomerKeyword = preg_replace('/[()\s]+/u', '', $escapedCustomerKeyword);
        $whereClauses[] = "{$normalizedCustomerExpr} LIKE '%{$normalizedCustomerKeyword}%'";
    } elseif (!$includeExcludedCustomer) {
        $escapedExcludedCustomerKeyword = $conn->real_escape_string($excludedCustomerKeyword);
        $normalizedExcludedCustomerKeyword = preg_replace('/[()\s]+/u', '', $escapedExcludedCustomerKeyword);
        $whereClauses[] = "(custname IS NULL OR {$normalizedCustomerExpr} NOT LIKE '%{$normalizedExcludedCustomerKeyword}%')";
    }

    if ($searchTerm !== '') {
        $escapedSearch = $conn->real_escape_string($searchTerm);
        $whereClauses[] = "(docno LIKE '%{$escapedSearch}%' OR custname LIKE '%{$escapedSearch}%')";
    }

    if ($startDate !== '' && $endDate !== '') {
        $escapedStartDate = $conn->real_escape_string($startDate);
        $escapedEndDate = $conn->real_escape_string($endDate);
        $whereClauses[] = "docdate BETWEEN '{$escapedStartDate}' AND '{$escapedEndDate}'";
    } elseif ($startDate !== '') {
        $escapedStartDate = $conn->real_escape_string($startDate);
        $whereClauses[] = "docdate >= '{$escapedStartDate}'";
    } elseif ($endDate !== '') {
        $escapedEndDate = $conn->real_escape_string($endDate);
        $whereClauses[] = "docdate <= '{$escapedEndDate}'";
    }

    $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    $debugInfo['final_where_clause'] = $whereSql;

    $countSql = 'SELECT COUNT(*) as total FROM transfer_data_from_mssql ' . $whereSql;
    $countResult = $conn->query($countSql);
    if ($countResult === false) {
        throw new Exception('Count Query failed: ' . $conn->error);
    }

    $totalItems = (int) $countResult->fetch_assoc()['total'];
    $totalPages = $totalItems > 0 ? (int) ceil($totalItems / $limit) : 0;

    if ($totalItems > 0) {
        $dataSql = "SELECT docno, docdate, custname AS customer_name, cd_code,
                       cd_name, qty, Lname_unit AS unit, REMARK, UNITPRICE, branch, shipflag,
                       location_code, location,
                       delivery_status, delivery_remark, last_update
                    FROM transfer_data_from_mssql {$whereSql}
                    ORDER BY last_update DESC, docdate DESC, docno DESC
                    LIMIT {$limit} OFFSET {$offset}";

        $result = $conn->query($dataSql);
        if ($result === false) {
            throw new Exception('Data Query failed: ' . $conn->error);
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
    app_json_response(['success' => false, 'message' => 'Query Error', 'error_detail' => $error, 'debug_info' => $debugInfo], 500);
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
    'debug_info' => $debugInfo,
]);
