<?php
// api/fetch_items.php

ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/db_config.php';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Connection Error']);
    exit;
}
$conn->set_charset("utf8");

// --- รับค่า Parameters ---
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$locationCode = isset($_GET['location']) ? trim($_GET['location']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$startDate = isset($_GET['startDate']) ? trim($_GET['startDate']) : '';
$endDate = isset($_GET['endDate']) ? trim($_GET['endDate']) : '';

$items = [];
$error = null;
$total_pages = 0;
$total_items = 0;
$debug_info = [];

try {
    // --- สร้างเงื่อนไข WHERE ---
    $whereClauses = [];
    
    if (!empty($status)) {
        $escapedStatus = $conn->real_escape_string($status);
        $whereClauses[] = "delivery_status = '{$escapedStatus}'";
    } else {
        $whereClauses[] = "(delivery_status IS NULL OR delivery_status = '')";
    }

    if (!empty($locationCode)) {
        $escapedLocation = $conn->real_escape_string($locationCode);
        $whereClauses[] = "TRIM(location_code) = '{$escapedLocation}'";
    }

    if (!empty($searchTerm)) {
        $escapedSearch = $conn->real_escape_string($searchTerm);
        $whereClauses[] = "(docno LIKE '%{$escapedSearch}%' OR custname LIKE '%{$escapedSearch}%')";
    }

    if (!empty($startDate) && !empty($endDate)) {
        $escapedStartDate = $conn->real_escape_string($startDate);
        $escapedEndDate = $conn->real_escape_string($endDate);
        $whereClauses[] = "docdate BETWEEN '{$escapedStartDate}' AND '{$escapedEndDate}'";
    } else if (!empty($startDate)) {
        $escapedStartDate = $conn->real_escape_string($startDate);
        $whereClauses[] = "docdate >= '{$escapedStartDate}'";
    } else if (!empty($endDate)) {
        $escapedEndDate = $conn->real_escape_string($endDate);
        $whereClauses[] = "docdate <= '{$escapedEndDate}'";
    }
    
    $whereSql = "WHERE " . implode(' AND ', $whereClauses);
    $debug_info['final_where_clause'] = $whereSql;

    $count_sql = "SELECT COUNT(*) as total FROM transfer_data_from_mssql " . $whereSql;
    $debug_info['count_sql'] = $count_sql;
    
    $count_result = $conn->query($count_sql);
    if ($count_result === false) { throw new Exception('Count Query failed: ' . $conn->error); }
    $total_items = $count_result->fetch_assoc()['total'];
    $total_pages = $total_items > 0 ? ceil($total_items / $limit) : 0;
    
    if ($total_items > 0) {
        // --- FIX: Added 'shipflag' to the SELECT statement ---
        $data_sql = "SELECT docno, docdate, custname AS customer_name, cd_code, 
                       cd_name, qty, Lname_unit AS unit, REMARK, UNITPRICE, branch, shipflag,
                       delivery_status, delivery_remark, last_update
                       FROM transfer_data_from_mssql " . $whereSql . " 
                       ORDER BY last_update DESC, docdate DESC, docno DESC
                       LIMIT {$limit} OFFSET {$offset}";
        
        $debug_info['data_sql'] = $data_sql;
        
        $result = $conn->query($data_sql);
        if ($result === false) { throw new Exception('Data Query failed: ' . $conn->error); }
        
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

$conn->close();

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query Error', 'error_detail' => $error, 'debug_info' => $debug_info]);
} else {
    echo json_encode([
        'success' => true, 
        'data' => $items,
        'pagination' => ['current_page' => $page, 'total_pages' => $total_pages, 'items_per_page' => $limit, 'total_items' => (int)$total_items],
        'debug_info' => $debug_info
    ]);
}
?>
